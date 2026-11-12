<?php

namespace Tests\Feature;

use App\Domain\Billing\EntitlementService;
use App\Models\Organization;
use App\Notifications\OrganizationInvite;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    /** Owner buys a family plan through the (fake) checkout; returns the group id. */
    private function familyOwner(): array
    {
        $owner = $this->actingAsUser($this->makeUser(['name' => 'Nadia Haddad']));
        $url = $this->postJson('/api/v1/billing/checkout', ['plan' => 'family_monthly'])->assertOk()->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->postJson('/api/v1/billing/checkout/confirm', ['session_id' => $q['session_id']])->assertOk();

        return [$owner, Organization::where('owner_id', $owner->id)->firstOrFail()->public_id];
    }

    public function test_family_plan_seats_invitations_and_inherited_entitlements(): void
    {
        Notification::fake();
        [$owner, $org] = $this->familyOwner();

        $group = $this->getJson("/api/v1/organizations/{$org}")->assertOk()->json('data');
        $this->assertSame('owner', $group['role']);
        $this->assertSame(['seats' => 6, 'used' => 1, 'members' => 1, 'pending' => 0], $group['seats']);

        $invite = $this->postJson("/api/v1/organizations/{$org}/invitations", ['email' => 'Omar@Example.test'])->assertCreated()->json('data');
        $this->assertSame('omar@example.test', $invite['email']);
        Notification::assertSentOnDemand(OrganizationInvite::class);
        $token = basename($invite['accept_url']);
        $this->assertDatabaseMissing('organization_invitations', ['token_hash' => $token]); // only the hash is stored

        // Someone else with the link cannot use it.
        $stranger = $this->actingAsUser($this->makeUser(['email' => 'stranger@example.test']));
        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.email_matches', false);
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(403)->assertJsonPath('code', 'invitation_email_mismatch');

        $member = $this->actingAsUser($this->makeUser(['email' => 'omar@example.test', 'name' => 'Omar Said']));
        $this->assertSame('free', app(EntitlementService::class)->resolve($member)['plan']->slug);
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertOk()->assertJsonPath('data.role', 'member');
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(410);
        app(EntitlementService::class)->forget();
        $this->getJson('/api/v1/billing/subscription')->assertOk()
            ->assertJsonPath('data.plan.slug', 'family_monthly')
            ->assertJsonPath('data.source', 'organization')
            ->assertJsonPath('data.subscription.can_manage', false);

        // Members see names, not emails; they cannot invite.
        $view = $this->getJson("/api/v1/organizations/{$org}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('email', $view['members'][0]);
        $this->assertSame([], $view['invitations']);
        $this->postJson("/api/v1/organizations/{$org}/invitations", ['email' => 'x@example.test'])->assertStatus(403);
        $this->getJson("/api/v1/organizations/{$org}")->assertOk();
        $this->actingAsUser($stranger);
        $this->getJson("/api/v1/organizations/{$org}")->assertStatus(404);

        // Owner removes the member → back to Free.
        $this->actingAsUser($owner);
        $memberId = collect($this->getJson("/api/v1/organizations/{$org}")->json('data.members'))->firstWhere('name', 'Omar Said')['id'];
        $this->deleteJson("/api/v1/organizations/{$org}/members/{$memberId}")->assertOk();
        app(EntitlementService::class)->forget();
        $this->assertSame('free', app(EntitlementService::class)->resolve($member)['plan']->slug);
    }

    public function test_seats_are_capped_by_the_plan(): void
    {
        Notification::fake();
        [, $org] = $this->familyOwner();
        foreach (range(1, 5) as $i) {
            $this->postJson("/api/v1/organizations/{$org}/invitations", ['email' => "kid{$i}@example.test"])->assertCreated();
        }
        $this->postJson("/api/v1/organizations/{$org}/invitations", ['email' => 'kid6@example.test'])
            ->assertStatus(409)->assertJsonPath('code', 'seats_exhausted');

        // Revoking frees the seat.
        $pending = $this->getJson("/api/v1/organizations/{$org}")->json('data.invitations');
        $this->deleteJson("/api/v1/organization-invitations/{$pending[0]['id']}")->assertOk();
        $this->postJson("/api/v1/organizations/{$org}/invitations", ['email' => 'kid6@example.test'])->assertCreated();
    }

    public function test_group_without_a_plan_cannot_invite(): void
    {
        $this->actingAsUser($this->makeUser());
        $org = $this->postJson('/api/v1/organizations', ['name' => 'Our family'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/organizations', ['name' => 'Second'])->assertStatus(409);
        $this->postJson("/api/v1/organizations/{$org}/invitations", ['email' => 'a@example.test'])->assertStatus(402)->assertJsonPath('code', 'plan_required');
    }
}
