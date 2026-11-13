<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_plans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active'); // active | paused | archived
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
        });
        // Partial unique indexes are Postgres-only; MySQL/MariaDB use a generated column
        // (NULL when archived) so archived plans do not block a new live plan per profile.
        // VIRTUAL, not STORED: MySQL 8.0 forbids ON DELETE CASCADE on the base column of a stored generated column.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX learning_plans_one_live_per_profile ON learning_plans (learner_profile_id) WHERE status <> 'archived'");
        } else {
            DB::statement("ALTER TABLE learning_plans ADD COLUMN live_learner_profile_id BIGINT UNSIGNED AS (IF(status <> 'archived', learner_profile_id, NULL)) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX learning_plans_one_live_per_profile ON learning_plans (live_learner_profile_id)');
        }

        Schema::create('learning_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('generator', 30); // rules_v1
            $table->string('trigger', 30); // initial | assessment | user_regenerate | settings_change
            $table->unsignedSmallInteger('daily_minutes');
            $table->date('valid_from');
            $table->date('valid_until');
            $table->jsonb('inputs_snapshot'); // immutable snapshot of the evidence used
            $table->text('rationale');
            $table->timestamp('created_at');
            $table->unique(['learning_plan_id', 'version_number']);
        });

        Schema::table('learning_plans', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('learning_plan_versions')->nullOnDelete();
        });

        Schema::create('learning_plan_version_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_plan_version_id')
                ->constrained(indexName: 'lpva_version_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->decimal('priority_score', 6, 3);
            $table->unsignedSmallInteger('minutes_per_week');
            $table->string('reason_code', 60);
            $table->unique(['learning_plan_version_id', 'skill_id'], 'lpva_version_skill_unique');
        });

        Schema::create('learning_plan_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('learning_plan_version_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->unsignedSmallInteger('position');
            $table->foreignId('skill_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('activity_type', 40);
            $table->string('topic', 80)->nullable(); // grammar/vocabulary topic slug the item targets
            $table->string('title', 160);
            $table->text('objective');
            $table->foreignId('target_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->unsignedSmallInteger('estimated_minutes');
            $table->string('reason_code', 60);
            $table->string('status', 20)->default('pending'); // pending | in_progress | completed | skipped
            $table->string('skip_reason', 120)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['learning_plan_version_id', 'scheduled_date'], 'lpi_version_date_index');
        });

        Schema::create('learning_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_plan_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('active_seconds')->default(0);
            $table->string('outcome', 20)->nullable(); // completed | abandoned
            $table->unsignedTinyInteger('self_rating')->nullable(); // 1–5 perceived difficulty
            $table->timestamps();
            $table->index(['learner_profile_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('learning_plans', fn (Blueprint $t) => $t->dropForeign(['current_version_id']));
        foreach (['learning_sessions', 'learning_plan_items', 'learning_plan_version_allocations',
            'learning_plan_versions', 'learning_plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
