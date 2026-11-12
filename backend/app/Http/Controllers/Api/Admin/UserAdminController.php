<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\EntitlementService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainError;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UserAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $users = User::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('email', 'ilike', "%{$q}%")->orWhere('name', 'ilike', "%{$q}%")))
            ->when($request->query('status'), fn ($query, $s) => $query->where('status', $s))
            ->with('roles')->latest('id')->paginate(min(100, (int) $request->query('per_page', 25)));

        return response()->json([
            'data' => collect($users->items())->map(fn (User $u) => self::row($u)),
            'meta' => ['page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
        ]);
    }

    public function show(User $user, EntitlementService $entitlements): JsonResponse
    {
        $user->load(['roles', 'learnerProfiles.targetLanguage', 'profile']);
        $resolved = $entitlements->resolve($user);

        return response()->json(['data' => [
            ...self::row($user),
            'timezone' => $user->profile?->timezone,
            'languages' => $user->learnerProfiles->map(fn ($p) => ['code' => $p->targetLanguage->code, 'status' => $p->status]),
            'plan' => ['slug' => $resolved['plan']->slug, 'source' => $resolved['source']],
            'subscriptions' => Subscription::where('user_id', $user->id)->with('plan')->latest('id')->get()->map(fn ($s) => self::subscription($s)),
            'available_roles' => Role::orderBy('id')->pluck('slug'),
        ]]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'in:active,suspended']])['status'];
        if ($user->id === $request->user()->id) {
            throw new DomainError('You cannot change your own status.', 'forbidden', 422);
        }
        $this->guardPrivileged($request->user(), $user);
        $old = $user->status;
        $user->update(['status' => $status]);
        if ($status === User::STATUS_SUSPENDED) {
            $user->tokens()->delete(); // end API sessions immediately
        }
        $this->audit->log('admin.user_status', $user, ['status' => $old], ['status' => $status]);

        return response()->json(['data' => self::row($user->fresh('roles'))]);
    }

    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $slugs = $request->validate(['roles' => ['present', 'array'], 'roles.*' => ['string', 'exists:roles,slug']])['roles'];
        $actor = $request->user();
        if ($user->id === $actor->id) {
            throw new DomainError('You cannot change your own roles.', 'forbidden', 422);
        }
        $privileged = ['admin', 'super_admin'];
        if (! $actor->hasRole('super_admin') && (array_intersect($slugs, $privileged) || $user->hasRole(...$privileged))) {
            throw new DomainError('Only a super administrator can grant or remove administrator roles.', 'forbidden', 403);
        }
        $old = $user->roles->pluck('slug')->all();
        $user->roles()->sync(Role::whereIn('slug', $slugs)->pluck('id'));
        $this->audit->log('admin.user_roles', $user, ['roles' => $old], ['roles' => $slugs]);

        return response()->json(['data' => self::row($user->fresh('roles'))]);
    }

    public function grant(Request $request, User $user, BillingService $billing): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'exists:subscription_plans,slug'],
            'until' => ['required', 'date', 'after:today', 'before:'.now()->addYears(2)->toDateString()],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $subscription = $billing->grantManual($user, SubscriptionPlan::where('slug', $data['plan'])->firstOrFail(), Carbon::parse($data['until'])->endOfDay(), $data['note'], $request->user());

        return response()->json(['data' => self::subscription($subscription->load('plan'))], 201);
    }

    private function guardPrivileged(User $actor, User $target): void
    {
        if ($target->hasRole('admin', 'super_admin') && ! $actor->hasRole('super_admin')) {
            throw new DomainError('Only a super administrator can change administrator accounts.', 'forbidden', 403);
        }
    }

    /** @return array<string, mixed> */
    public static function row(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status,
            'roles' => $u->roles->pluck('slug'), 'created_at' => $u->created_at->toIso8601String(),
            'email_verified' => $u->email_verified_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    public static function subscription(Subscription $s): array
    {
        return [
            'id' => $s->public_id, 'plan' => $s->plan->slug, 'status' => $s->status, 'provider' => $s->provider,
            'current_period_end' => $s->current_period_end?->toIso8601String(), 'cancel_at_period_end' => $s->cancel_at_period_end,
            'note' => $s->note, 'created_at' => $s->created_at->toIso8601String(),
            'user' => $s->relationLoaded('user') ? ['id' => $s->user->id, 'name' => $s->user->name, 'email' => $s->user->email] : null,
        ];
    }
}
