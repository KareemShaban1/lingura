<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data: languages, capabilities, skills, CEFR levels, learning purposes, RBAC.
 * These are lookup tables (not PG enums) so administrators can extend them without migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique(); // BCP-47 primary subtag, e.g. "en", "ar"
            $table->string('name', 80);
            $table->string('native_name', 80);
            $table->string('direction', 3)->default('ltr'); // ltr | rtl
            $table->string('script', 8)->default('Latn'); // ISO 15924
            $table->boolean('is_active')->default(true);
            $table->boolean('is_target_enabled')->default(true);
            $table->boolean('is_interface_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('language_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique(); // en-GB, ar-EG …
            $table->string('name', 80);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('language_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40); // tts, stt, pronunciation, content_generation, realtime_voice, writing_evaluation
            $table->string('provider_slug', 40)->default('default');
            $table->string('status', 20); // supported | beta | unsupported
            $table->unsignedTinyInteger('quality_tier')->default(1);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['language_id', 'feature', 'provider_slug']);
        });

        Schema::create('language_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_language_id')->constrained('languages')->cascadeOnDelete();
            $table->foreignId('target_language_id')->constrained('languages')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('explanation_quality', 20)->default('supported');
            $table->timestamps();
            $table->unique(['source_language_id', 'target_language_id']);
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();
            $table->string('name', 60);
            $table->boolean('is_productive')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('cefr_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique(); // PRE_A1 … C2
            $table->string('label', 20);
            $table->unsignedTinyInteger('ordinal')->unique(); // 0 … 6
            $table->decimal('anchor_theta', 5, 2); // nominal Rasch difficulty anchor
            $table->text('description');
            $table->timestamps();
        });

        Schema::create('learning_purposes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 80);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('learning_purpose_skill_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_purpose_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight', 4, 2);
            $table->unique(['learning_purpose_id', 'skill_id'], 'lp_skill_weights_unique');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 80);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['role_user', 'permission_role', 'permissions', 'roles', 'learning_purpose_skill_weights',
            'learning_purposes', 'cefr_levels', 'skills', 'language_pairs', 'language_capabilities',
            'language_variants', 'languages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
