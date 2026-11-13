<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Logged bandit decisions (with propensities) so personalisation can be audited and evaluated off-policy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personalization_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->string('policy', 30); // e.g. thompson_beta_v1
            $table->string('context', 40); // decision point, e.g. skill "grammar"
            $table->jsonb('arms'); // [{arm, alpha, beta, sample, propensity}]
            $table->string('chosen_arm', 40);
            $table->decimal('propensity', 6, 4); // P(chosen arm) under the policy at decision time
            $table->foreignId('exercise_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('reward', 4, 3)->nullable();
            $table->timestamp('rewarded_at')->nullable();
            // useCurrent: MySQL rejects a second TIMESTAMP NOT NULL after rewarded_at without an explicit default
            $table->timestamp('created_at')->useCurrent();
            $table->index(['learner_profile_id', 'context', 'chosen_arm'], 'pd_profile_context_arm_index');
            $table->index(['learner_profile_id', 'exercise_id', 'reward'], 'pd_profile_exercise_reward_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personalization_decisions');
    }
};
