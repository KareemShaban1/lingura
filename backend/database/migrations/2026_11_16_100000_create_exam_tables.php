<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: exam preparation. Frameworks describe an exam's published format; forms are original practice
 * material in that format. Results are always estimates derived from our ability model, never official scores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->boolean('is_exam')->default(false); // exam tasks stay out of open writing practice and plans
        });
        Schema::create('exam_frameworks', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->string('exam_name', 80); // descriptive use of the exam's name
            $table->string('scale', 20); // band (estimated band score) | readiness (estimated chance of passing a level)
            $table->foreignId('target_cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('prior_cefr_level_id')->constrained('cefr_levels')->restrictOnDelete(); // nominal level of the form
            $table->string('overall_rule', 30); // mean_half_band | all_sections
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('exam_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_framework_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('duration_seconds');
            $table->unique(['exam_framework_id', 'skill_id']);
        });
        Schema::create('exam_score_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_framework_id')->constrained()->cascadeOnDelete();
            $table->decimal('theta', 5, 2);
            $table->decimal('value', 5, 2); // interpolated piecewise-linearly; documented as indicative
            $table->unique(['exam_framework_id', 'theta']);
        });
        Schema::create('exam_forms', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('exam_framework_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60)->unique();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('content_hash', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('exam_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('exam_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('group_key', 40); // items sharing a passage / recording
            $table->text('stimulus_text')->nullable(); // passage, or the listening script (never shown during the section)
            $table->string('audio_path')->nullable();
            $table->text('prompt');
            $table->text('explanation')->nullable();
            $table->decimal('difficulty', 5, 2);
            $table->timestamps();
            $table->index(['exam_form_id', 'exam_section_id', 'position']);
        });
        Schema::create('exam_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_item_id')->constrained()->cascadeOnDelete();
            $table->text('label');
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('position');
        });
        Schema::create('exam_form_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_task_id')->constrained()->restrictOnDelete();
            $table->unique(['exam_form_id', 'exam_section_id']);
        });

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_form_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('in_progress'); // in_progress | completed | abandoned
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('result')->nullable(); // overall estimate snapshot
            $table->timestamps();
            $table->index(['learner_profile_id', 'status']);
        });
        Schema::create('exam_attempt_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_section_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // pending | in_progress | completed | expired | skipped | unavailable
            $table->string('reason', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('deadline_at')->nullable(); // server-authoritative timer
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('raw_correct')->nullable();
            $table->unsignedSmallInteger('raw_total')->nullable();
            $table->decimal('theta', 6, 3)->nullable();
            $table->decimal('standard_error', 6, 3)->nullable();
            $table->foreignId('cefr_level_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('estimate')->nullable();
            $table->foreignId('writing_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audio_recording_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['exam_attempt_id', 'exam_section_id']);
        });
        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('exam_item_option_id')->constrained()->restrictOnDelete();
            $table->boolean('is_correct');
            $table->timestamp('answered_at');
            $table->unique(['exam_attempt_section_id', 'exam_item_id']); // one current answer per item (changeable until submit)
        });
    }

    public function down(): void
    {
        foreach (['exam_answers', 'exam_attempt_sections', 'exam_attempts', 'exam_form_tasks', 'exam_item_options', 'exam_items', 'exam_forms', 'exam_score_conversions', 'exam_sections', 'exam_frameworks'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('production_tasks', fn (Blueprint $t) => $t->dropColumn('is_exam'));
    }
};
