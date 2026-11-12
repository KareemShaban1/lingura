<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('type', 20); // placement | progress | exam
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['slug', 'version']);
        });

        Schema::create('assessment_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('min_items');
            $table->unsignedSmallInteger('max_items');
            $table->decimal('target_standard_error', 4, 2);
            $table->unsignedInteger('time_limit_seconds')->nullable();
            $table->string('availability', 20)->default('enabled'); // enabled | coming_soon
            $table->timestamps();
            $table->unique(['assessment_id', 'skill_id']);
        });

        Schema::create('assessment_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete();
            $table->string('format', 30); // mcq | cloze_mcq | reading_mcq | listening_mcq | true_false
            $table->decimal('difficulty', 5, 2);
            $table->text('stimulus_text')->nullable(); // reading passage or listening transcript
            $table->string('stimulus_key', 64)->nullable(); // groups items sharing a passage
            $table->string('audio_path')->nullable();
            $table->text('prompt');
            $table->text('explanation')->nullable();
            $table->string('grammar_topic_slug', 80)->nullable();
            $table->string('source', 20)->default('authored'); // authored | ai_generated
            $table->string('review_status', 20)->default('draft'); // draft | in_review | published | retired
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('exposure_count')->default(0);
            $table->string('content_hash', 64)->unique(); // de-duplicates seeded / generated items
            $table->timestamps();
            $table->softDeletes();
            $table->index(['language_id', 'skill_id', 'review_status', 'difficulty']);
        });

        Schema::create('assessment_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_item_id')->constrained()->cascadeOnDelete();
            $table->text('label');
            $table->unsignedSmallInteger('position');
            $table->boolean('is_correct')->default(false);
            $table->timestamps();
            $table->unique(['assessment_item_id', 'position']);
        });

        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('in_progress'); // in_progress | completed | abandoned
            $table->string('engine_version', 20);
            $table->foreignId('overall_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->boolean('overall_is_provisional')->default(true);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['learner_profile_id', 'status']);
        });

        Schema::create('assessment_attempt_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_section_id')->constrained()->restrictOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('status', 20)->default('pending'); // pending | in_progress | completed | skipped | not_available
            $table->decimal('theta', 6, 3)->nullable();
            $table->decimal('standard_error', 6, 3)->nullable();
            $table->unsignedSmallInteger('items_answered')->default(0);
            $table->unsignedSmallInteger('items_correct')->default(0);
            $table->foreignId('estimated_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->string('skip_reason', 60)->nullable();
            // The item currently served to the learner; answers are only accepted for this item.
            $table->foreignId('current_item_id')->nullable()->constrained('assessment_items')->nullOnDelete();
            $table->timestamp('current_item_served_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['assessment_attempt_id', 'skill_id']);
        });

        Schema::create('assessment_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_attempt_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('selected_option_id')->nullable()->constrained('assessment_item_options')->nullOnDelete();
            $table->boolean('is_correct');
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->decimal('theta_after', 6, 3);
            $table->decimal('se_after', 6, 3);
            $table->timestamp('answered_at');
            $table->timestamps();
            $table->unique(['assessment_attempt_id', 'assessment_item_id']);
        });

        Schema::create('skill_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('theta', 6, 3);
            $table->decimal('standard_error', 6, 3);
            $table->unsignedTinyInteger('scaled_score');
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('evidence_count');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['skill_profile_id', 'recorded_at']);
        });

        Schema::create('assessment_report_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // strength | weakness | recommendation
            $table->string('code', 60);
            $table->string('detail', 255)->nullable(); // e.g. grammar topic slug
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->index(['assessment_attempt_id', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['assessment_report_findings', 'skill_scores', 'assessment_answers', 'assessment_attempt_sections',
            'assessment_attempts', 'assessment_item_options', 'assessment_items', 'assessment_sections',
            'assessments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
