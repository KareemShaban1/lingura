<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: prompt registry, AI tutor, speech (recordings, transcription, pronunciation),
 * writing evaluation, and adaptive mistake patterns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- prompt registry
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_prompt_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('template');
            $table->jsonb('json_schema')->nullable();
            $table->string('status', 20)->default('active'); // draft | active | retired
            $table->timestamps();
            $table->unique(['ai_prompt_id', 'version']);
        });

        // ---------------------------------------------------------------- tutor
        Schema::create('ai_tutors', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 60);
            $table->string('voice', 40)->default('alloy');
            $table->text('persona'); // English persona description used in prompts
            $table->string('style', 20); // friendly | professional | playful | strict
            $table->string('correction_mode', 20)->default('gentle'); // gentle | every_mistake | end_only
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('conversation_scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->foreignId('min_level_id')->constrained('cefr_levels')->restrictOnDelete();
            $table->foreignId('max_level_id')->constrained('cefr_levels')->restrictOnDelete();
            $table->text('setup'); // English instructions for the model: roles and situation
            $table->string('icon', 30)->default('message');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_tutor_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_scenario_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete(); // difficulty
            $table->foreignId('learning_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 10)->default('text'); // text | voice | realtime
            $table->string('status', 20)->default('active'); // active | completed | report_failed
            $table->string('prompt_version', 40);
            $table->text('rolling_summary')->nullable(); // compacted older context
            $table->unsignedInteger('learner_turns')->default(0);
            $table->unsignedInteger('learner_words')->default(0);
            $table->unsignedInteger('speaking_seconds')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['learner_profile_id', 'started_at']);
        });

        // ---------------------------------------------------------------- speech
        Schema::create('audio_recordings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30); // conversation | pronunciation | speaking_assessment
            $table->string('disk', 20);
            $table->string('path');
            $table->string('mime_type', 60);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->char('checksum', 64);
            $table->timestamp('expires_at'); // privacy: recordings are deleted after the retention window
            $table->timestamps();
            $table->index(['learner_profile_id', 'created_at']);
            $table->index('expires_at');
        });
        Schema::create('speech_transcriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_recording_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('language', 12);
            $table->string('provider_slug', 40);
            $table->string('model_slug', 80);
            $table->text('text');
            $table->timestamps();
        });
        Schema::create('pronunciation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_recording_id')->constrained()->cascadeOnDelete();
            $table->text('reference_text');
            $table->decimal('accuracy', 5, 4); // matched reference words / reference words
            $table->decimal('completeness', 5, 4); // reference words attempted (matched or substituted)
            $table->unsignedSmallInteger('words_per_minute')->nullable();
            $table->string('method', 30)->default('transcript_alignment_v1');
            $table->timestamps();
        });
        Schema::create('pronunciation_word_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pronunciation_result_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('expected', 120)->nullable();
            $table->string('heard', 120)->nullable();
            $table->string('status', 12); // correct | substituted | omitted | inserted
        });

        // ---------------------------------------------------------------- tutor messages & reports
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12); // assistant | learner
            $table->text('content');
            $table->foreignId('audio_recording_id')->nullable()->constrained()->nullOnDelete();
            $table->text('natural_alternative')->nullable(); // a more natural way to say the learner's message
            $table->string('source', 12)->default('text'); // text | voice | realtime
            $table->unsignedInteger('response_ms')->nullable();
            $table->timestamp('created_at');
            $table->index(['ai_conversation_id', 'id']);
        });
        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ai_message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->string('source', 12); // tutor | writing | speaking
            $table->string('type', 20); // grammar | vocabulary | word_choice | spelling | pronunciation
            $table->string('topic_slug', 80)->nullable(); // linked grammar topic when identifiable
            $table->text('original');
            $table->text('correction');
            $table->text('explanation'); // in the learner's explanation language
            $table->timestamp('created_at');
            $table->index(['learner_profile_id', 'created_at']);
        });
        Schema::create('ai_conversation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('summary');
            $table->text('fluency_feedback');
            $table->text('pronunciation_feedback')->nullable();
            $table->unsignedTinyInteger('task_completion'); // 1–5 rubric
            $table->unsignedTinyInteger('grammar_score'); // 1–5
            $table->unsignedTinyInteger('vocabulary_score'); // 1–5
            $table->unsignedTinyInteger('interaction_score'); // 1–5
            $table->string('prompt_version', 40);
            $table->string('model_slug', 80);
            $table->timestamps();
        });
        Schema::create('ai_conversation_report_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_report_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // vocabulary | expression | exercise
            $table->string('term', 200);
            $table->text('detail')->nullable();
            $table->foreignId('vocabulary_word_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
        });

        // ---------------------------------------------------------------- writing
        // Productive tasks: written (sentences, email, paragraph, essay, summary) or spoken (a prompt to answer aloud).
        Schema::create('production_tasks', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key', 80)->unique();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete();
            $table->string('mode', 10); // written | spoken
            $table->string('type', 20); // sentences | email | paragraph | essay | summary | monologue
            $table->text('prompt'); // in the target language
            $table->text('source_text')->nullable(); // for summaries
            $table->unsignedSmallInteger('min_words'); // expected length in words (spoken: words in the transcript)
            $table->unsignedSmallInteger('max_words');
            $table->boolean('is_placement')->default(false);
            $table->string('review_status', 20)->default('published');
            $table->timestamps();
        });
        Schema::create('writing_submissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_task_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->text('text');
            $table->unsignedSmallInteger('word_count');
            $table->string('status', 20)->default('pending'); // pending | evaluated | failed
            $table->timestamps();
            $table->index(['learner_profile_id', 'created_at']);
        });
        Schema::create('writing_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('writing_submission_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('cefr_level_id')->constrained()->restrictOnDelete(); // estimated level of the text
            $table->decimal('overall_score', 4, 2); // mean of criterion bands (1–5), shown with the criteria
            $table->text('summary');
            $table->text('corrected_text');
            $table->unsignedSmallInteger('sentence_count');
            $table->decimal('avg_sentence_length', 5, 2);
            $table->decimal('lexical_variety', 5, 4); // type–token ratio
            $table->boolean('needs_human_review')->default(false); // calibration sample
            $table->string('prompt_version', 40);
            $table->string('model_slug', 80);
            $table->timestamps();
        });
        Schema::create('writing_evaluation_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('writing_evaluation_id')->constrained()->cascadeOnDelete();
            $table->string('criterion', 30); // task_achievement | grammar | vocabulary | coherence | organization
            $table->unsignedTinyInteger('score'); // 1–5
            $table->text('comment');
            $table->unique(['writing_evaluation_id', 'criterion']);
        });
        Schema::create('writing_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('writing_evaluation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('start'); // character offset in the submitted text (verified server-side)
            $table->unsignedInteger('length');
            $table->text('original');
            $table->text('correction');
            $table->string('type', 20);
            $table->text('explanation');
        });

        // ---------------------------------------------------------------- adaptive engine
        Schema::create('learner_mistake_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20); // grammar | vocabulary | word_choice | spelling | pronunciation
            $table->string('topic_slug', 80)->default('');
            $table->unsignedInteger('occurrences');
            $table->unsignedSmallInteger('sources'); // distinct sources (tutor, writing, exercises)
            $table->text('example')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['learner_profile_id', 'category', 'topic_slug']);
        });

        Schema::table('assessment_attempt_sections', function (Blueprint $table) {
            $table->foreignId('production_task_id')->nullable()->constrained()->nullOnDelete(); // productive sections
            $table->foreignId('writing_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audio_recording_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('learning_plan_items', function (Blueprint $table) {
            $table->foreignId('production_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_scenario_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('exercise_answers', function (Blueprint $table) {
            $table->foreignId('audio_recording_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('exercise_questions', function (Blueprint $table) {
            $table->text('reference_text')->nullable(); // repeat-after-me: sentence to pronounce
        });
    }

    public function down(): void
    {
        Schema::table('exercise_questions', fn (Blueprint $t) => $t->dropColumn('reference_text'));
        Schema::table('exercise_answers', fn (Blueprint $t) => $t->dropConstrainedForeignId('audio_recording_id'));
        Schema::table('learning_plan_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('conversation_scenario_id');
            $t->dropConstrainedForeignId('production_task_id');
        });
        Schema::table('assessment_attempt_sections', function (Blueprint $t) {
            $t->dropConstrainedForeignId('production_task_id');
            $t->dropConstrainedForeignId('audio_recording_id');
            $t->dropConstrainedForeignId('writing_submission_id');
        });
        foreach (['learner_mistake_patterns', 'writing_corrections', 'writing_evaluation_criteria', 'writing_evaluations',
            'writing_submissions', 'production_tasks', 'ai_conversation_report_items', 'ai_conversation_reports', 'ai_feedback',
            'ai_messages', 'pronunciation_word_results', 'pronunciation_results', 'speech_transcriptions', 'audio_recordings',
            'ai_conversations', 'conversation_scenarios', 'ai_tutors', 'ai_prompt_versions', 'ai_prompts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
