<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organizations\OrganizationService;
use App\Domain\Shared\DomainError;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationService $orgs) {}

    public function index(Request $request): JsonResponse
    {
        $memberships = OrganizationMember::where('user_id', $request->user()->id)->with('organization')->get();

        return response()->json(['data' => $memberships->map(fn ($m) => [
            'id' => $m->organization->public_id, 'name' => $m->organization->name, 'type' => $m->organization->type, 'role' => $m->role,
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']])['name'];

        return response()->json(['data' => $this->payload($this->orgs->create($request->user(), $name), $request->user())], 201);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->member($organization, $request->user());

        return response()->json(['data' => $this->payload($organization, $request->user())]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $this->orgs->assertAdmin($organization, $request->user());
        $organization->update($request->validate(['name' => ['required', 'string', 'min:2', 'max:120']]));

        return response()->json(['data' => $this->payload($organization, $request->user())]);
    }

    public function invite(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'role' => ['nullable', 'in:member,admin']]);
        $result = $this->orgs->invite($organization, $request->user(), $data['email'], $data['role'] ?? 'member');

        // The inviter may share the link directly; it only works for the invited email address.
        return response()->json(['data' => [
            ...self::invitation($result['invitation']),
            'accept_url' => $this->orgs->acceptUrl($result['token']),
        ]], 201);
    }

    public function revokeInvitation(Request $request, OrganizationInvitation $invitation): JsonResponse
    {
        $this->orgs->revoke($invitation, $request->user());

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function previewInvitation(Request $request, string $token): JsonResponse
    {
        $invitation = $this->orgs->findByToken($token);

        return response()->json(['data' => [
            'organization' => $invitation->organization->name,
            'invited_by' => $invitation->organization->owner->name,
            'status' => $invitation->accepted_at ? 'accepted' : ($invitation->isPending() ? 'pending' : 'expired'),
            'email_matches' => Str::lower($request->user()->email) === $invitation->email,
        ]]);
    }

    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $org = $this->orgs->accept($token, $request->user());

        return response()->json(['data' => $this->payload($org, $request->user())]);
    }

    public function removeMember(Request $request, Organization $organization, int $member): JsonResponse
    {
        $this->orgs->removeMember($organization, OrganizationMember::findOrFail($member), $request->user());

        return response()->json(['data' => $this->payload($organization, $request->user())]);
    }

    public function leave(Request $request, Organization $organization): JsonResponse
    {
        $this->orgs->leave($organization, $request->user());

        return response()->json(['data' => ['left' => true]]);
    }

    private function member(Organization $org, User $user): string
    {
        return $org->roleOf($user) ?? throw new DomainError('Not found.', 'not_found', 404);
    }

    /** @return array<string, mixed> */
    private function payload(Organization $org, User $viewer): array
    {
        $role = $this->member($org, $viewer);
        $isAdmin = in_array($role, ['owner', 'admin'], true);
        $subscription = $org->activeSubscription();

        return [
            'id' => $org->public_id,
            'name' => $org->name,
            'type' => $org->type,
            'role' => $role,
            'plan' => $subscription ? ['slug' => $subscription->plan->slug, 'status' => $subscription->status, 'current_period_end' => $subscription->current_period_end?->toIso8601String()] : null,
            'seats' => $this->orgs->seats($org),
            'members' => $org->members()->with('user')->orderBy('joined_at')->get()->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->user->name, 'role' => $m->role, 'joined_at' => $m->joined_at->toIso8601String(),
                'is_you' => $m->user_id === $viewer->id,
                ...($isAdmin ? ['email' => $m->user->email] : []), // emails only for the group's admins
            ]),
            'invitations' => $isAdmin ? $org->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
                ->latest('id')->get()->map(fn ($i) => self::invitation($i)) : [],
        ];
    }

    /** @return array<string, mixed> */
    private static function invitation(OrganizationInvitation $i): array
    {
        return ['id' => $i->public_id, 'email' => $i->email, 'role' => $i->role, 'expires_at' => $i->expires_at->toIso8601String()];
    }
}
