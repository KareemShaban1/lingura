<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 2 learner state: lesson progress, exercise attempts, mastery, spaced repetition, daily rollups. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_sessions', function (Blueprint $table) {
            $table->string('activity_type', 40)->nullable()->after('learning_plan_item_id');
        });

        Schema::table('learning_plan_items', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->after('topic')->constrained()->nullOnDelete();
            $table->foreignId('exercise_id')->nullable()->after('lesson_id')->constrained()->nullOnDelete();
        });

        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('in_progress'); // in_progress | completed
            $table->decimal('best_score', 5, 4)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['learner_profile_id', 'lesson_id']);
        });

        Schema::create('exercise_attempts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('in_progress'); // in_progress | completed
            $table->unsignedSmallInteger('questions_total');
            $table->unsignedSmallInteger('questions_answered')->default(0);
            $table->decimal('score_sum', 7, 3)->default(0);
            $table->decimal('score', 5, 4)->nullable(); // 0–1 on completion
            $table->unsignedInteger('reading_seconds')->nullable();
            $table->unsignedInteger('words_count')->nullable();
            $table->unsignedSmallInteger('words_per_minute')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['learner_profile_id', 'exercise_id']);
            $table->index(['learner_profile_id', 'completed_at']);
        });

        Schema::create('exercise_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('selected_option_id')->nullable()->constrained('exercise_options')->nullOnDelete();
            $table->text('response_text')->nullable(); // typed answer, or ordered option ids "4,2,9"
            $table->boolean('is_correct');
            $table->decimal('score', 5, 4); // partial credit for near-misses and dictation
            $table->string('feedback_code', 40)->nullable(); // e.g. accents, word_accuracy
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamp('answered_at');
            $table->timestamps();
            $table->unique(['exercise_attempt_id', 'exercise_question_id']);
        });

        Schema::create('grammar_mastery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grammar_topic_id')->constrained()->cascadeOnDelete();
            $table->decimal('mastery', 5, 4); // exponentially weighted accuracy, 0–1
            $table->unsignedInteger('answers_total')->default(0);
            $table->unsignedInteger('answers_correct')->default(0);
            $table->timestamp('last_practiced_at');
            $table->timestamps();
            $table->unique(['learner_profile_id', 'grammar_topic_id']);
        });

        Schema::create('user_vocabulary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vocabulary_word_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20); // lesson | reading | manual | exercise
            $table->string('state', 12)->default('new'); // new | learning | review | relearning
            $table->decimal('stability', 10, 4)->nullable(); // FSRS stability (days)
            $table->decimal('difficulty', 6, 4)->nullable(); // FSRS difficulty (1–10)
            $table->unsignedInteger('reps')->default(0);
            $table->unsignedInteger('lapses')->default(0);
            $table->timestamp('due_at');
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['learner_profile_id', 'vocabulary_word_id']);
            $table->index(['learner_profile_id', 'due_at']);
        });

        Schema::create('flashcard_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_vocabulary_id')->constrained('user_vocabulary')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1 again | 2 hard | 3 good | 4 easy
            $table->string('state_before', 12);
            $table->decimal('retrievability', 6, 4)->nullable();
            $table->decimal('stability_after', 10, 4);
            $table->decimal('difficulty_after', 6, 4);
            $table->decimal('elapsed_days', 8, 3);
            $table->decimal('scheduled_days', 8, 3);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('reviewed_at');
            $table->index(['user_vocabulary_id', 'reviewed_at']);
        });

        Schema::create('vocabulary_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['learner_profile_id', 'name']);
        });

        Schema::create('vocabulary_collection_items', function (Blueprint $table) {
            $table->foreignId('vocabulary_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_vocabulary_id')->constrained('user_vocabulary')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['vocabulary_collection_id', 'user_vocabulary_id'], 'vocab_collection_items_pk');
        });

        // Daily per-skill rollup for analytics (local calendar date of the learner).
        Schema::create('user_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('questions_answered')->default(0);
            $table->unsignedInteger('questions_correct')->default(0);
            $table->unsignedInteger('exercises_completed')->default(0);
            $table->unsignedInteger('words_reviewed')->default(0);
            $table->timestamps();
            $table->unique(['learner_profile_id', 'date', 'skill_id']);
        });
    }

    public function down(): void
    {
        foreach (['user_progress', 'vocabulary_collection_items', 'vocabulary_collections', 'flashcard_reviews', 'user_vocabulary',
            'grammar_mastery', 'exercise_answers', 'exercise_attempts', 'lesson_progress'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('learning_plan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exercise_id');
            $table->dropConstrainedForeignId('lesson_id');
        });
        Schema::table('learning_sessions', fn (Blueprint $t) => $t->dropColumn('activity_type'));
    }
};
