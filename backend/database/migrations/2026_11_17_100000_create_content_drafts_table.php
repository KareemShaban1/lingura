<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** In-app authored exercises: draft → in review → published (imported) or rejected (back to editing). */
    public function up(): void
    {
        Schema::create('content_drafts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->string('exercise_key', 120);
            $table->json('payload');
            $table->string('status', 20)->default('draft'); // draft | in_review | published | rejected
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->foreignId('exercise_id')->nullable()->constrained()->nullOnDelete(); // set once published
            $table->foreignId('replaces_exercise_id')->nullable()->constrained('exercises')->nullOnDelete(); // retired on publish
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
            $table->index('exercise_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_drafts');
    }
};
