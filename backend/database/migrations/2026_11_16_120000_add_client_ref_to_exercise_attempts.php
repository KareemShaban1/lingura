<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Offline sync: the client's id for an attempt made offline, so replays are idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercise_attempts', function (Blueprint $table) {
            $table->string('client_ref', 40)->nullable();
            $table->unique(['learner_profile_id', 'client_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('exercise_attempts', function (Blueprint $table) {
            $table->dropUnique(['learner_profile_id', 'client_ref']);
            $table->dropColumn('client_ref');
        });
    }
};
