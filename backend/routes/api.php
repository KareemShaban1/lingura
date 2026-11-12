<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\LearnerProfileController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\PlanController;
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
    });
});
