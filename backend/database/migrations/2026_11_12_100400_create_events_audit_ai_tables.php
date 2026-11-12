<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_profile_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('value', 12, 3)->nullable();
            $table->jsonb('metadata')->nullable(); // immutable event context only
            $table->timestamp('occurred_at');
            $table->index(['learner_profile_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at');
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_user_id', 'created_at']);
        });

        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 80);
            $table->string('driver', 40);
            $table->boolean('is_enabled')->default(true);
            $table->jsonb('config')->nullable(); // non-secret settings only
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('operation', 20); // text | tts | stt | realtime
            $table->decimal('input_cost_per_mtok', 10, 4)->nullable();
            $table->decimal('output_cost_per_mtok', 10, 4)->nullable();
            $table->decimal('audio_cost_per_minute', 10, 4)->nullable();
            $table->decimal('cost_per_mchar', 10, 4)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->unique(['ai_provider_id', 'slug', 'operation']);
        });

        Schema::create('ai_usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learner_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_slug', 40);
            $table->string('model_slug', 80);
            $table->string('operation', 40); // e.g. assessment.item_generation, tts.item_audio
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('audio_seconds')->default(0);
            $table->unsignedInteger('characters')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0); // USD × 1e6
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 20); // success | error
            $table->string('error_code', 80)->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['ai_model_id', 'created_at']);
            $table->index(['operation', 'created_at']);
        });

        Schema::create('data_export_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending | ready | failed | expired
            $table->string('path')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['data_export_requests', 'ai_usage_records', 'ai_models', 'ai_providers', 'audit_logs', 'learning_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
