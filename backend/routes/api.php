<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\GrammarController;
use App\Http\Controllers\Api\LearnerProfileController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PracticeController;
use App\Http\Controllers\Api\RecordingController;
use App\Http\Controllers\Api\TutorController;
use App\Http\Controllers\Api\VocabularyController;
use App\Http\Controllers\Api\WritingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('openapi.yaml', OpenApiController::class);

    // Public catalog
    Route::middleware('throttle:api')->group(function () {
        Route::get('languages', [CatalogController::class, 'languages']);
        Route::get('languages/{code}', [CatalogController::class, 'language']);
        Route::get('catalog/onboarding', [CatalogController::class, 'onboarding']);
    });

    // Authentication
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::get('media/{path}', [MediaController::class, 'stream'])->name('media.stream')->middleware('signed');

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::patch('me/profile', [MeController::class, 'updateProfile']);
        Route::patch('me/preferences', [MeController::class, 'updatePreferences']);
        Route::get('me/data-exports', [MeController::class, 'exports']);
        Route::post('me/data-exports', [MeController::class, 'requestExport']);
        Route::get('me/data-exports/{export}/download', [MeController::class, 'downloadExport']);
        Route::delete('me', [MeController::class, 'destroy']);

        Route::get('learner-profiles', [LearnerProfileController::class, 'index']);
        Route::post('learner-profiles', [LearnerProfileController::class, 'store']);
        Route::get('learner-profiles/{learnerProfile}', [LearnerProfileController::class, 'show']);
        Route::patch('learner-profiles/{learnerProfile}', [LearnerProfileController::class, 'update']);
        Route::delete('learner-profiles/{learnerProfile}', [LearnerProfileController::class, 'destroy']);
        Route::get('learner-profiles/{learnerProfile}/dashboard', [LearnerProfileController::class, 'dashboard']);
        Route::get('learner-profiles/{learnerProfile}/progress', [LearnerProfileController::class, 'progress']);

        Route::get('learner-profiles/{learnerProfile}/assessment-attempts', [AssessmentController::class, 'history']);
        Route::post('learner-profiles/{learnerProfile}/assessments', [AssessmentController::class, 'start']);
        Route::middleware('throttle:assessment')->group(function () {
            Route::get('assessment-attempts/{attempt}', [AssessmentController::class, 'show']);
            Route::get('assessment-attempts/{attempt}/next', [AssessmentController::class, 'next']);
            Route::post('assessment-attempts/{attempt}/answers', [AssessmentController::class, 'answer']);
            Route::post('assessment-attempts/{attempt}/sections/{skill}/skip', [AssessmentController::class, 'skip']);
            Route::post('assessment-attempts/{attempt}/complete', [AssessmentController::class, 'complete']);
            Route::get('assessment-attempts/{attempt}/report', [AssessmentController::class, 'report']);
            Route::get('assessment-attempts/{attempt}/items/{item}/audio', [AssessmentController::class, 'itemAudio']);
        });

        Route::get('learner-profiles/{learnerProfile}/plan', [PlanController::class, 'show']);
        Route::get('learner-profiles/{learnerProfile}/plan/items', [PlanController::class, 'items']);
        Route::get('learner-profiles/{learnerProfile}/plan/versions', [PlanController::class, 'versions']);
        Route::post('learner-profiles/{learnerProfile}/plan/regenerate', [PlanController::class, 'regenerate']);
        Route::post('learner-profiles/{learnerProfile}/plan/pause', [PlanController::class, 'pause']);
        Route::post('learner-profiles/{learnerProfile}/plan/resume', [PlanController::class, 'resume']);
        Route::post('plan-items/{planItem}/start', [PlanController::class, 'startItem']);
        Route::post('plan-items/{planItem}/skip', [PlanController::class, 'skipItem']);
        Route::get('learning-sessions/{session}', [PlanController::class, 'showSession']);
        Route::post('learning-sessions/{session}/complete', [PlanController::class, 'completeSession']);
        Route::post('learner-profiles/{learnerProfile}/learning-sessions', [PlanController::class, 'startSession']);

        // Phase 2 — courses, lessons, grammar
        Route::get('learner-profiles/{learnerProfile}/courses', [CourseController::class, 'index']);
        Route::get('learner-profiles/{learnerProfile}/lessons/{lesson}', [CourseController::class, 'show']);
        Route::post('learner-profiles/{learnerProfile}/lessons/{lesson}/complete', [CourseController::class, 'complete']);
        Route::get('learner-profiles/{learnerProfile}/grammar-topics', [GrammarController::class, 'index']);
        Route::get('learner-profiles/{learnerProfile}/grammar-topics/{slug}', [GrammarController::class, 'show']);

        // Phase 2 — practice exercises
        Route::get('learner-profiles/{learnerProfile}/exercises', [PracticeController::class, 'index']);
        Route::post('learner-profiles/{learnerProfile}/exercises/{exercise}/attempts', [PracticeController::class, 'start']);
        Route::get('exercise-attempts/{exerciseAttempt}', [PracticeController::class, 'show']);
        Route::post('exercise-attempts/{exerciseAttempt}/answers', [PracticeController::class, 'answer']);
        Route::post('exercise-attempts/{exerciseAttempt}/finish', [PracticeController::class, 'finish']);
        Route::get('exercise-attempts/{exerciseAttempt}/audio', [PracticeController::class, 'audio']);

        // Phase 2 — vocabulary & spaced repetition
        Route::get('learner-profiles/{learnerProfile}/vocabulary', [VocabularyController::class, 'index']);
        Route::post('learner-profiles/{learnerProfile}/vocabulary', [VocabularyController::class, 'store']);
        Route::get('learner-profiles/{learnerProfile}/vocabulary/summary', [VocabularyController::class, 'summary']);
        Route::get('learner-profiles/{learnerProfile}/vocabulary/review-queue', [VocabularyController::class, 'queue']);
        Route::get('learner-profiles/{learnerProfile}/vocabulary/lookup', [VocabularyController::class, 'lookup']);
        Route::post('learner-profiles/{learnerProfile}/vocabulary/extract', [VocabularyController::class, 'extract']);
        Route::post('user-vocabulary/{userVocabulary}/reviews', [VocabularyController::class, 'review']);
        Route::delete('user-vocabulary/{userVocabulary}', [VocabularyController::class, 'destroy']);
        Route::get('learner-profiles/{learnerProfile}/vocabulary-collections', [VocabularyController::class, 'collections']);
        Route::post('learner-profiles/{learnerProfile}/vocabulary-collections', [VocabularyController::class, 'createCollection']);
        Route::delete('vocabulary-collections/{collection}', [VocabularyController::class, 'deleteCollection']);
        Route::post('vocabulary-collections/{collection}/items', [VocabularyController::class, 'toggleCollectionItem']);

        // Phase 3 — speech
        Route::post('learner-profiles/{learnerProfile}/recordings', [RecordingController::class, 'store'])->middleware('throttle:ai');
        Route::get('recordings/{recording}/url', [RecordingController::class, 'url']);
        Route::post('assessment-attempts/{attempt}/writing', [AssessmentController::class, 'writing'])->middleware('throttle:ai');
        Route::post('assessment-attempts/{attempt}/speaking', [AssessmentController::class, 'speaking'])->middleware('throttle:ai');

        // Phase 3 — AI tutor
        Route::get('learner-profiles/{learnerProfile}/tutor/catalog', [TutorController::class, 'catalog']);
        Route::get('learner-profiles/{learnerProfile}/conversations', [TutorController::class, 'index']);
        Route::post('learner-profiles/{learnerProfile}/conversations', [TutorController::class, 'start'])->middleware('throttle:ai');
        Route::get('conversations/{conversation}', [TutorController::class, 'show']);
        Route::post('conversations/{conversation}/messages', [TutorController::class, 'message'])->middleware('throttle:ai');
        Route::post('conversations/{conversation}/voice', [TutorController::class, 'voice'])->middleware('throttle:ai');
        Route::post('conversations/{conversation}/realtime-session', [TutorController::class, 'realtimeSession'])->middleware('throttle:ai');
        Route::post('conversations/{conversation}/realtime-transcript', [TutorController::class, 'realtimeTranscript']);
        Route::post('conversations/{conversation}/finish', [TutorController::class, 'finish']);
        Route::get('conversations/{conversation}/report', [TutorController::class, 'report']);
        Route::post('conversations/{conversation}/report/retry', [TutorController::class, 'retryReport'])->middleware('throttle:ai');
        Route::get('ai-messages/{message}/audio', [TutorController::class, 'messageAudio'])->middleware('throttle:ai');

        // Phase 3 — writing
        Route::get('learner-profiles/{learnerProfile}/writing-tasks', [WritingController::class, 'tasks']);
        Route::get('learner-profiles/{learnerProfile}/writing-tasks/{task}', [WritingController::class, 'task']);
        Route::post('learner-profiles/{learnerProfile}/writing-tasks/{task}/submissions', [WritingController::class, 'submit'])->middleware('throttle:ai');
        Route::get('writing-submissions/{submission}', [WritingController::class, 'show']);
    });
});
