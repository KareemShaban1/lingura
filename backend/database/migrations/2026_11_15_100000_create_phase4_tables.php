<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4: billing & entitlements, organizations, gamification, admin review. */
return new class extends Migration
{
    public function up(): void
    {
        // ── Organizations (family / school / company) ──────────────────────────────
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 120);
            $table->string('type', 20)->default('family'); // family | school | company
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member'); // owner | admin | member
            $table->timestamp('joined_at');
            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20)->default('member');
            $table->string('token_hash', 64)->unique(); // sha256 of the emailed token; the token itself is never stored
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });

        // ── Billing ───────────────────────────────────────────────────────────────
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('tier', 20); // free | premium | pro | family
            $table->string('interval', 10)->nullable(); // month | year (null = free)
            $table->unsignedInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('provider_price_id', 120)->nullable()->unique(); // e.g. Stripe price id
            $table->unsignedSmallInteger('seats')->default(1);
            $table->unsignedSmallInteger('rank')->default(0); // higher rank wins when a user has several sources
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });
        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40);
            $table->unsignedInteger('limit')->nullable(); // null = unlimited
            $table->string('period', 10); // day | month | total
            $table->unique(['subscription_plan_id', 'feature']);
        });
        Schema::create('billing_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_customer_id', 120);
            $table->timestamps();
            $table->unique(['provider', 'provider_customer_id']);
            $table->unique(['user_id', 'provider']);
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the payer
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete(); // seat plans
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20); // stripe | fake | manual
            $table->string('provider_subscription_id', 120)->nullable()->unique();
            $table->string('status', 20); // incomplete | trialing | active | past_due | canceled | unpaid
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('note')->nullable(); // manual grants: why
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['organization_id', 'status']);
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_invoice_id', 120)->unique();
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 20); // paid | failed | refunded
            $table->string('hosted_invoice_url', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id', 120);
            $table->string('type', 80);
            $table->jsonb('payload');
            $table->string('status', 20)->default('received'); // received | processed | ignored | failed
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']); // webhook idempotency
        });
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40);
            $table->date('period_start');
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['user_id', 'feature', 'period_start']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // ── Gamification ──────────────────────────────────────────────────────────
        Schema::create('xp_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_profile_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source', 30); // exercise | lesson | vocabulary | tutor | writing | assessment | achievement | challenge
            $table->integer('amount');
            $table->string('dedupe_key', 120)->unique(); // one award per verified outcome
            $table->date('earned_on'); // in the learner's timezone
            $table->timestamp('created_at');
            $table->index(['user_id', 'earned_on']);
            $table->index(['earned_on', 'user_id']);
        });
        Schema::create('streaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('current_days')->default(0);
            $table->unsignedInteger('longest_days')->default(0);
            $table->date('last_active_on')->nullable();
            $table->unsignedTinyInteger('freezes_available')->default(0);
            $table->timestamps();
        });
        Schema::create('streak_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 10); // active | frozen
            $table->unique(['user_id', 'date']);
        });
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('metric', 40); // lessons_completed | exercises_completed | words_learned | streak_days | conversations | writings | assessments | xp_total
            $table->unsignedInteger('threshold');
            $table->unsignedInteger('xp_reward')->default(0);
            $table->string('icon', 40)->default('award');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('achievement_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocked_at');
            $table->unique(['user_id', 'achievement_id']);
        });
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60);
            $table->string('metric', 40); // xp | exercises | reviews | minutes | conversations
            $table->unsignedInteger('target');
            $table->unsignedInteger('xp_reward')->default(0);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->unique(['slug', 'starts_on']);
        });
        Schema::create('challenge_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('progress')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'user_id']);
        });
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->boolean('leaderboard_opt_in')->default(false); // privacy: off unless the learner opts in
            $table->string('leaderboard_alias', 40)->nullable();
        });

        // ── Admin review ─────────────────────────────────────────────────────────
        Schema::create('content_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('reviewable_type', 60);
            $table->unsignedBigInteger('reviewable_id');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20); // approved | rejected | retired | restored
            $table->text('notes')->nullable();
            $table->timestamp('created_at');
            $table->index(['reviewable_type', 'reviewable_id']);
        });
        Schema::table('writing_evaluations', function (Blueprint $table) {
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->decimal('human_overall_score', 4, 2)->nullable();
            $table->text('review_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('writing_evaluations', fn (Blueprint $t) => $t->dropConstrainedForeignId('reviewed_by'));
        Schema::table('writing_evaluations', fn (Blueprint $t) => $t->dropColumn(['reviewed_at', 'human_overall_score', 'review_notes']));
        Schema::table('user_preferences', fn (Blueprint $t) => $t->dropColumn(['leaderboard_opt_in', 'leaderboard_alias']));
        foreach (['content_reviews', 'challenge_progress', 'challenges', 'user_achievements', 'achievements', 'streak_days', 'streaks', 'xp_ledger',
            'notifications', 'usage_counters', 'billing_events', 'payments', 'subscriptions', 'billing_customers', 'plan_entitlements',
            'subscription_plans', 'organization_invitations', 'organization_members', 'organizations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
