<?php

namespace App\Domain\Organizations;

use App\Domain\Billing\EntitlementService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainError;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Notifications\OrganizationInvite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Family groups (and later schools/companies). Members inherit the group's plan while it is live;
 * seats = members + pending invitations, capped by the plan's `seats`. Members see each other's names
 * only — no learning data is shared through a group.
 */
class OrganizationService
{
    public function __construct(private readonly AuditLogger $audit, private readonly EntitlementService $entitlements) {}

    public function create(User $owner, string $name): Organization
    {
        if (Organization::where('owner_id', $owner->id)->where('type', 'family')->exists()) {
            throw new DomainError('You already have a family group.', 'organization_exists', 409);
        }

        return DB::transaction(function () use ($owner, $name) {
            $org = Organization::create(['name' => $name, 'type' => 'family', 'owner_id' => $owner->id]);
            OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);
            $this->audit->log('organization.created', $org, new: ['name' => $name], actorId: $owner->id);

            return $org;
        });
    }

    /** @return array{seats: int, used: int, members: int, pending: int} */
    public function seats(Organization $org): array
    {
        $seats = $org->activeSubscription()?->plan->seats ?? 1;
        $members = $org->members()->count();
        $pending = $org->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();

        return ['seats' => $seats, 'used' => $members + $pending, 'members' => $members, 'pending' => $pending];
    }

    /** @return array{invitation: OrganizationInvitation, token: string} */
    public function invite(Organization $org, User $inviter, string $email, string $role = 'member'): array
    {
        $this->assertAdmin($org, $inviter);
        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($org, $inviter, $email, $role) {
            Organization::whereKey($org->id)->lockForUpdate()->first(); // serialise seat counting
            if (! $org->activeSubscription()) {
                throw new DomainError('Start a family plan to invite members.', 'plan_required', 402);
            }
            $seats = $this->seats($org);
            if ($seats['used'] >= $seats['seats']) {
                throw new DomainError('All seats of your plan are in use.', 'seats_exhausted', 409);
            }
            if ($org->members()->whereHas('user', fn ($q) => $q->whereRaw('lower(email) = ?', [$email]))->exists()) {
                throw new DomainError('This person is already a member.', 'already_member', 409);
            }
            // Re-inviting the same address replaces the previous pending invitation.
            $org->invitations()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $token = Str::random(48);
            $invitation = $org->invitations()->create([
                'email' => $email, 'role' => $role === 'admin' ? 'admin' : 'member', 'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->id, 'expires_at' => now()->addDays(config('billing.invitation_ttl_days')),
            ]);
            $this->audit->log('organization.invited', $invitation, new: ['email' => $email], actorId: $inviter->id);
            Notification::route('mail', $email)->notify(new OrganizationInvite($org->name, $inviter->name, $this->acceptUrl($token)));

            return ['invitation' => $invitation, 'token' => $token];
        });
    }

    public function findByToken(string $token): OrganizationInvitation
    {
        return OrganizationInvitation::where('token_hash', hash('sha256', $token))->with('organization.owner')->first()
            ?? throw new DomainError('This invitation link is not valid.', 'invitation_invalid', 404);
    }

    public function accept(string $token, User $user): Organization
    {
        $invitation = $this->findByToken($token);
        if (! $invitation->isPending()) {
            throw new DomainError('This invitation has expired or was already used.', 'invitation_expired', 410);
        }
        if (Str::lower($user->email) !== $invitation->email) {
            throw new DomainError('This invitation was sent to a different email address.', 'invitation_email_mismatch', 403);
        }
        $org = $invitation->organization;

        DB::transaction(function () use ($invitation, $org, $user) {
            Organization::whereKey($org->id)->lockForUpdate()->first();
            if ($org->members()->where('user_id', $user->id)->exists()) {
                throw new DomainError('You are already a member of this group.', 'already_member', 409);
            }
            if (! $org->activeSubscription()) {
                throw new DomainError('This group has no active plan right now.', 'plan_required', 402);
            }
            // The invitation itself holds the seat, so acceptance never exceeds the plan.
            OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $invitation->role, 'joined_at' => now()]);
            $invitation->update(['accepted_at' => now()]);
            $this->audit->log('organization.joined', $org, new: ['user_id' => $user->id], actorId: $user->id);
        });
        $this->entitlements->forget($user);
        $org->owner->notify(new AccountNotice('family_member_joined', ['name' => $user->name, 'group' => $org->name]));

        return $org;
    }

    public function revoke(OrganizationInvitation $invitation, User $actor): void
    {
        $this->assertAdmin($invitation->organization, $actor);
        $invitation->update(['revoked_at' => now()]);
        $this->audit->log('organization.invitation_revoked', $invitation, actorId: $actor->id);
    }

    public function removeMember(Organization $org, OrganizationMember $member, User $actor): void
    {
        $this->assertAdmin($org, $actor);
        if ($member->organization_id !== $org->id) {
            throw new DomainError('Member not found.', 'not_found', 404);
        }
        if ($member->role === 'owner') {
            throw new DomainError('The owner cannot be removed.', 'owner_protected', 422);
        }
        $member->delete();
        $this->audit->log('organization.member_removed', $org, old: ['user_id' => $member->user_id], actorId: $actor->id);
        $member->user?->notify(new AccountNotice('family_removed', ['group' => $org->name]));
    }

    public function leave(Organization $org, User $user): void
    {
        $member = $org->members()->where('user_id', $user->id)->firstOrFail();
        if ($member->role === 'owner') {
            throw new DomainError('Owners cannot leave their own group.', 'owner_protected', 422);
        }
        $member->delete();
        $this->audit->log('organization.left', $org, old: ['user_id' => $user->id], actorId: $user->id);
    }

    public function assertAdmin(Organization $org, User $user): void
    {
        if (! in_array($org->roleOf($user), ['owner', 'admin'], true)) {
            throw new DomainError('Only the group owner can do this.', 'forbidden', 403);
        }
    }

    public function acceptUrl(string $token): string
    {
        return rtrim(config('app.frontend_url'), '/').'/invitations/'.$token;
    }
}
