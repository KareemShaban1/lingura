<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 content: translations, grammar topics, vocabulary, exercises, courses and lessons.
 * Authored content is immutable once published; learner state lives in separate tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Localised strings for any content row (dynamic localisation without per-locale columns).
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translatable_type', 60);
            $table->unsignedBigInteger('translatable_id');
            $table->string('field', 40);
            $table->string('locale', 12);
            $table->text('value');
            $table->timestamps();
            $table->unique(['translatable_type', 'translatable_id', 'field', 'locale'], 'translations_unique');
        });

        Schema::create('grammar_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->string('slug', 80);
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('review_status', 20)->default('published');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['language_id', 'slug']);
        });

        Schema::create('grammar_topic_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grammar_topic_id')->constrained()->cascadeOnDelete();
            $table->text('sentence');
            $table->text('translation')->nullable(); // English gloss
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });

        Schema::create('vocabulary_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->string('lemma', 120);
            $table->string('normalized', 120);
            $table->string('part_of_speech', 20);
            $table->string('gender', 10)->nullable();
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('frequency_rank')->nullable();
            $table->string('ipa', 120)->nullable();
            $table->string('audio_path')->nullable();
            $table->string('review_status', 20)->default('published');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['language_id', 'lemma', 'part_of_speech']);
            $table->index(['language_id', 'normalized']);
        });

        // Inflected forms so words can be recognised in running text (e.g. "casas" → casa).
        // utf8mb4_bin: unicode_ci equates ä/a so "Laden"/"Läden" collide after lowercasing.
        Schema::create('vocabulary_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_word_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->string('form', 120)->collation('utf8mb4_bin');
            } else {
                $table->string('form', 120);
            }
            $table->unique(['vocabulary_word_id', 'form']);
            $table->index(['language_id', 'form']);
        });

        Schema::create('vocabulary_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_word_id')->constrained()->cascadeOnDelete();
            $table->text('sentence');
            $table->text('translation')->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });

        Schema::create('vocabulary_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_word_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12); // synonym | antonym
            $table->string('value', 120);
            $table->unique(['vocabulary_word_id', 'type', 'value']);
        });

        Schema::create('learning_objectives', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->foreignId('language_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key', 80)->unique(); // stable authoring key
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('grammar_topic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // mcq | cloze | ordering | reading | listening | dictation
            $table->decimal('difficulty', 5, 2);
            $table->unsignedSmallInteger('estimated_seconds');
            $table->text('stimulus_text')->nullable(); // reading passage / listening transcript
            $table->string('audio_path')->nullable();
            $table->string('source', 20)->default('authored');
            $table->string('content_hash', 64)->nullable(); // detects edits to published content
            $table->string('review_status', 20)->default('draft');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['language_id', 'skill_id', 'review_status', 'cefr_level_id'], 'exercises_lookup_index');
        });

        Schema::create('exercise_objectives', function (Blueprint $table) {
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_objective_id')->constrained()->cascadeOnDelete();
            $table->primary(['exercise_id', 'learning_objective_id']);
        });

        Schema::create('exercise_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('prompt');
            $table->text('explanation')->nullable();
            $table->string('audio_path')->nullable(); // dictation sentences
            $table->timestamps();
            $table->unique(['exercise_id', 'position']);
        });

        Schema::create('exercise_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_question_id')->constrained()->cascadeOnDelete();
            $table->text('label');
            $table->unsignedSmallInteger('position'); // display position (for ordering: shuffled display)
            $table->unsignedSmallInteger('correct_position')->nullable(); // ordering: position in the correct sequence
            $table->boolean('is_correct')->default(false);
            $table->timestamps();
        });

        Schema::create('exercise_accepted_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_question_id')->constrained()->cascadeOnDelete();
            $table->text('answer');
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('slug', 80)->unique();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->foreignId('level_from_id')->constrained('cefr_levels')->restrictOnDelete();
            $table->foreignId('level_to_id')->constrained('cefr_levels')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('review_status', 20)->default('published');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('course_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->unique(['course_id', 'position']);
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key', 80)->unique();
            $table->foreignId('course_unit_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('grammar_topic_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('estimated_minutes');
            $table->string('review_status', 20)->default('published');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['course_unit_id', 'position']);
        });

        Schema::create('lesson_objectives', function (Blueprint $table) {
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_objective_id')->constrained()->cascadeOnDelete();
            $table->primary(['lesson_id', 'learning_objective_id']);
        });

        Schema::create('lesson_words', function (Blueprint $table) {
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vocabulary_word_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->primary(['lesson_id', 'vocabulary_word_id']);
        });

        Schema::create('lesson_exercises', function (Blueprint $table) {
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->primary(['lesson_id', 'exercise_id']);
        });
    }

    public function down(): void
    {
        foreach (['lesson_exercises', 'lesson_words', 'lesson_objectives', 'lessons', 'course_units', 'courses',
            'exercise_accepted_answers', 'exercise_options', 'exercise_questions', 'exercise_objectives', 'exercises',
            'learning_objectives', 'vocabulary_relations', 'vocabulary_examples', 'vocabulary_forms', 'vocabulary_words',
            'grammar_topic_examples', 'grammar_topics', 'translations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
