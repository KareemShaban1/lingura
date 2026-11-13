<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('native_language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->foreignId('interface_language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->string('timezone', 64)->default('UTC');
            $table->char('country_code', 2)->nullable();
            $table->timestamps();
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('theme', 10)->default('system'); // light | dark | system
            $table->boolean('daily_reminder_enabled')->default(true);
            $table->time('reminder_time')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('data_processing_consent_at')->nullable();
            $table->timestamps();
        });

        // One learner profile per (user, target language). This is the aggregate root for learning state.
        Schema::create('learner_profiles', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_language_id')->constrained('languages')->restrictOnDelete();
            $table->foreignId('language_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_purpose_id')->constrained()->restrictOnDelete();
            $table->foreignId('self_reported_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('target_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->unsignedSmallInteger('daily_minutes');
            $table->string('status', 24)->default('assessment_pending');
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('last_studied_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'status']);
        });
        // Partial unique indexes are Postgres-only; MySQL/MariaDB use a generated column
        // (NULL when soft-deleted) so deleted rows do not block recreating the same pair.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX learner_profiles_user_language_unique ON learner_profiles (user_id, target_language_id) WHERE deleted_at IS NULL');
        } else {
            DB::statement('ALTER TABLE learner_profiles ADD COLUMN active_target_language_id BIGINT UNSIGNED AS (IF(deleted_at IS NULL, target_language_id, NULL)) STORED');
            DB::statement('CREATE UNIQUE INDEX learner_profiles_user_language_unique ON learner_profiles (user_id, active_target_language_id)');
        }

        Schema::create('learner_focus_skills', function (Blueprint $table) {
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['learner_profile_id', 'skill_id']);
        });

        Schema::create('study_schedule_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday … 6 = Saturday (ISO-agnostic, matches JS/Carbon)
            $table->string('preferred_time', 12)->default('evening'); // morning|afternoon|evening|HH:MM
            $table->timestamps();
            $table->unique(['learner_profile_id', 'day_of_week']);
        });

        Schema::create('learning_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->string('preference_key', 60);
            $table->string('value', 255);
            $table->timestamps();
            $table->unique(['learner_profile_id', 'preference_key']);
        });

        Schema::create('skill_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('theta', 6, 3)->nullable();
            $table->decimal('standard_error', 6, 3)->nullable();
            $table->string('confidence', 10)->nullable(); // low | medium | high
            $table->string('source', 20)->default('self_report'); // placement | practice | self_report
            $table->string('status', 20)->default('not_assessed'); // assessed | not_assessed
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();
            $table->unique(['learner_profile_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        foreach (['skill_profiles', 'learning_preferences', 'study_schedule_slots', 'learner_focus_skills',
            'learner_profiles', 'user_preferences', 'user_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
