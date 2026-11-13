<?php

use App\Http\Controllers\Api\Admin\AiAdminController;
use App\Http\Controllers\Api\Admin\AnalyticsController;
use App\Http\Controllers\Api\Admin\BillingAdminController;
use App\Http\Controllers\Api\Admin\ContentAdminController;
use App\Http\Controllers\Api\Admin\ReviewController;
use App\Http\Controllers\Api\Admin\SystemAdminController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\GamificationController;
use App\Http\Controllers\Api\GrammarController;
use App\Http\Controllers\Api\LearnerProfileController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\OrganizationController;
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
        Route::get('billing/plans', [BillingController::class, 'plans']);
    });

    // Payment provider webhooks: authenticated by signature, not by session.
    Route::post('webhooks/billing', [BillingController::class, 'webhook'])->middleware('throttle:120,1');

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

        // Phase 4 — billing & notifications
        Route::get('billing/subscription', [BillingController::class, 'subscription']);
        Route::post('billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1');
        Route::post('billing/checkout/confirm', [BillingController::class, 'confirm'])->middleware('throttle:10,1');
        Route::post('billing/subscription/cancel', [BillingController::class, 'cancel']);
        Route::post('billing/subscription/resume', [BillingController::class, 'resume']);
        Route::post('billing/portal', [BillingController::class, 'portal']);
        Route::get('billing/payments', [BillingController::class, 'payments']);
        Route::get('me/notifications', [NotificationController::class, 'index']);
        Route::post('me/notifications/read', [NotificationController::class, 'markRead']);

        // Phase 4 — family / organizations
        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::post('organizations', [OrganizationController::class, 'store']);
        Route::get('organizations/{organization}', [OrganizationController::class, 'show']);
        Route::patch('organizations/{organization}', [OrganizationController::class, 'update']);
        Route::post('organizations/{organization}/invitations', [OrganizationController::class, 'invite'])->middleware('throttle:20,1');
        Route::delete('organization-invitations/{invitation}', [OrganizationController::class, 'revokeInvitation']);
        Route::get('invitations/{token}', [OrganizationController::class, 'previewInvitation'])->middleware('throttle:20,1');
        Route::post('invitations/{token}/accept', [OrganizationController::class, 'acceptInvitation'])->middleware('throttle:20,1');
        Route::delete('organizations/{organization}/members/{member}', [OrganizationController::class, 'removeMember']);
        Route::post('organizations/{organization}/leave', [OrganizationController::class, 'leave']);

        // Phase 4 — gamification
        Route::get('me/gamification', [GamificationController::class, 'summary']);
        Route::get('me/xp', [GamificationController::class, 'xp']);
        Route::get('me/achievements', [GamificationController::class, 'achievements']);
        Route::get('me/challenges', [GamificationController::class, 'challenges']);
        Route::get('leaderboards/weekly', [GamificationController::class, 'leaderboard']);
        Route::patch('me/leaderboard', [GamificationController::class, 'updateLeaderboard']);

        // Phase 5 — exam preparation
        Route::get('learner-profiles/{learnerProfile}/exams', [ExamController::class, 'index']);
        Route::post('learner-profiles/{learnerProfile}/exams/{framework}/attempts', [ExamController::class, 'start'])->middleware('throttle:10,1');
        Route::get('exam-attempts/{attempt}', [ExamController::class, 'show']);
        Route::post('exam-attempts/{attempt}/sections/{skill}/start', [ExamController::class, 'startSection']);
        Route::post('exam-attempts/{attempt}/answers', [ExamController::class, 'answer']);
        Route::post('exam-attempts/{attempt}/sections/{skill}/submit', [ExamController::class, 'submit'])->middleware('throttle:ai');
        Route::post('exam-attempts/{attempt}/sections/{skill}/skip', [ExamController::class, 'skip']);
        Route::get('exam-attempts/{attempt}/review', [ExamController::class, 'review']);
        Route::get('exam-attempts/{attempt}/items/{item}/audio', [ExamController::class, 'audio']);

        // Phase 4 — administration (RBAC: admin.access + a per-area permission; every mutation is audited)
        Route::prefix('admin')->group(function () {
            Route::get('analytics/overview', [AnalyticsController::class, 'overview'])->middleware('permission:analytics.view');

            Route::middleware('permission:users.manage')->group(function () {
                Route::get('users', [UserAdminController::class, 'index']);
                Route::get('users/{user}', [UserAdminController::class, 'show']);
                Route::patch('users/{user}/status', [UserAdminController::class, 'updateStatus']);
                Route::put('users/{user}/roles', [UserAdminController::class, 'updateRoles']);
            });
            Route::post('users/{user}/grants', [UserAdminController::class, 'grant'])->middleware('permission:users.manage,billing.manage');

            Route::middleware('permission:content.review')->group(function () {
                Route::get('review/items', [ReviewController::class, 'items']);
                Route::post('review/items/{item}', [ReviewController::class, 'decideItem']);
                Route::get('review/evaluations', [ReviewController::class, 'evaluations']);
                Route::post('review/evaluations/{evaluation}', [ReviewController::class, 'reviewEvaluation']);
            });
            Route::middleware('permission:content.manage')->group(function () {
                Route::get('content/summary', [ContentAdminController::class, 'summary']);
                Route::get('content/exercises', [ContentAdminController::class, 'exercises']);
                Route::patch('content/exercises/{exercise}', [ContentAdminController::class, 'setExerciseStatus']);
                Route::post('content/import', [ContentAdminController::class, 'import'])->middleware('throttle:10,1');
            });
            Route::middleware('permission:ai.manage')->group(function () {
                Route::get('ai/providers', [AiAdminController::class, 'providers']);
                Route::patch('ai/providers/{provider}', [AiAdminController::class, 'updateProvider']);
                Route::patch('ai/models/{model}', [AiAdminController::class, 'updateModel']);
                Route::get('ai/prompts', [AiAdminController::class, 'prompts']);
                Route::post('ai/prompts/{key}/versions', [AiAdminController::class, 'createVersion']);
                Route::post('ai/prompt-versions/{version}/activate', [AiAdminController::class, 'activate']);
            });
            Route::middleware('permission:billing.manage')->group(function () {
                Route::get('billing/subscriptions', [BillingAdminController::class, 'subscriptions']);
                Route::post('billing/subscriptions/{subscription}/revoke', [BillingAdminController::class, 'revoke']);
                Route::get('billing/plans', [BillingAdminController::class, 'plans']);
                Route::patch('billing/plans/{plan}', [BillingAdminController::class, 'updatePlan']);
                Route::get('billing/events', [BillingAdminController::class, 'events']);
            });
            Route::middleware('permission:languages.manage')->group(function () {
                Route::get('languages', [SystemAdminController::class, 'languages']);
                Route::patch('language-capabilities/{capability}', [SystemAdminController::class, 'updateCapability']);
            });
            Route::get('audit-logs', [SystemAdminController::class, 'audit'])->middleware('permission:audit.view');
        });
    });
});
