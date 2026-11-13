<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContentDraft;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class ContentDraftTest extends TestCase
{
    private function staff(string $role): User
    {
        $user = $this->makeUser();
        $user->roles()->sync([Role::where('slug', $role)->value('id')]);

        return $user->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'es-b1-editor-mcq', 'type' => 'mcq', 'skill' => 'grammar', 'level' => 'B1', 'topic' => 'por_para',
            'title' => ['en' => 'Por or para?', 'ar' => 'por أم para؟'],
            'objectives' => [['code' => 'es.b1.por_para_choice', 'skill' => 'grammar', 'text' => ['en' => 'Choose between por and para']]],
            'questions' => [
                ['p' => 'Salgo ___ Madrid mañana.', 'o' => ['para', 'por', 'de', 'en'], 'e' => 'Destination → para.'],
                ['p' => 'Te cambio mi libro ___ el tuyo.', 'o' => ['por', 'para', 'a', 'con']],
            ],
        ], $overrides);
    }

    public function test_draft_is_checked_reviewed_by_someone_else_and_published_into_practice(): void
    {
        $author = $this->staff('content_editor');
        $reviewer = $this->staff('content_editor');
        $this->actingAsUser($author);

        // Bad content is refused by the same rules as the importer, before anything is stored.
        $this->postJson('/api/v1/admin/content/drafts', ['language' => 'es', 'payload' => $this->payload(['key' => 'fr-wrong-lang'])])->assertStatus(422)->assertJsonValidationErrors('key');
        $this->postJson('/api/v1/admin/content/drafts', ['language' => 'es', 'payload' => $this->payload(['questions' => [['p' => 'Sin opciones', 'o' => ['solo']]]])])->assertStatus(422);

        $draft = $this->postJson('/api/v1/admin/content/drafts', ['language' => 'es', 'payload' => $this->payload()])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.editable', true)->json('data');
        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/check")->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.replaces_existing', false);
        $this->assertNull(Exercise::where('key', 'es-b1-editor-mcq')->first(), 'a check writes nothing');

        // Duplicate options pass the form rules but are caught by the importer dry run.
        $this->putJson("/api/v1/admin/content/drafts/{$draft['id']}", ['payload' => $this->payload(['questions' => [['p' => 'A ___ b', 'o' => ['x', 'x']]]])])->assertOk();
        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/submit")->assertStatus(422)->assertJsonPath('code', 'invalid_draft');
        $this->putJson("/api/v1/admin/content/drafts/{$draft['id']}", ['payload' => $this->payload()])->assertOk();

        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/submit")->assertOk()->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.editable', false);
        $this->putJson("/api/v1/admin/content/drafts/{$draft['id']}", ['payload' => $this->payload()])->assertStatus(409);
        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/approve")->assertStatus(403)->assertJsonPath('code', 'self_review');

        $this->actingAsUser($reviewer);
        $this->getJson('/api/v1/admin/content/drafts?status=in_review')->assertOk()->assertJsonPath('data.0.can_review', true);
        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/reject", [])->assertStatus(422); // a reason is required
        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/reject", ['notes' => 'Add a third question.'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->actingAsUser($author);
        $three = $this->payload();
        $three['questions'][] = ['p' => 'Gracias ___ venir.', 'o' => ['por', 'para', 'de', 'sin']];
        $this->putJson("/api/v1/admin/content/drafts/{$draft['id']}", ['payload' => $three])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/submit")->assertOk();

        $this->actingAsUser($reviewer);
        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/approve", ['notes' => 'Good.'])->assertOk()->assertJsonPath('data.status', 'published');
        $exercise = Exercise::where('key', 'es-b1-editor-mcq')->with('questions.options')->firstOrFail();
        $this->assertSame('published', $exercise->review_status);
        $this->assertCount(3, $exercise->questions);
        $this->assertSame('para', $exercise->questions[0]->options->firstWhere('is_correct', true)->label);
        $this->assertSame(['admin.content_draft_created', 'admin.content_draft_published', 'admin.content_draft_rejected', 'admin.content_draft_submitted', 'admin.content_draft_updated'],
            AuditLog::where('action', 'like', 'admin.content_draft_%')->distinct()->orderBy('action')->pluck('action')->all());

        // Learners see it in the practice library.
        $learner = $this->makeLearner('es');
        $this->actingAsUser($learner->user);
        $keys = collect($this->getJson("/api/v1/learner-profiles/{$learner->public_id}/exercises?skill=grammar")->assertOk()->json('data'))
            ->map(fn ($e) => Exercise::where('public_id', $e['id'])->value('key'));
        $this->assertContains('es-b1-editor-mcq', $keys);
    }

    public function test_editing_an_exercise_with_attempts_makes_a_new_key_that_retires_the_original(): void
    {
        $author = $this->staff('content_editor');
        $reviewer = $this->staff('content_editor');
        $original = Exercise::where('key', 'es-a1-gustar-mcq')->firstOrFail();
        $profile = $this->makeLearner('es');
        ExerciseAttempt::create(['learner_profile_id' => $profile->id, 'exercise_id' => $original->id, 'status' => 'completed', 'started_at' => now(), 'completed_at' => now(), 'score' => 1, 'questions_total' => 4]);

        $this->actingAsUser($author);
        $draft = $this->postJson("/api/v1/admin/content/exercises/{$original->public_id}/drafts")->assertCreated()
            ->assertJsonPath('data.key', 'es-a1-gustar-mcq-v2')->assertJsonPath('data.replaces', 'es-a1-gustar-mcq')->json('data');
        // The export round-trips: the first option of every question is the correct one.
        $first = $original->questions()->orderBy('position')->first();
        $this->assertSame($first->options()->where('is_correct', true)->value('label'), $draft['payload']['questions'][0]['o'][0]);

        // Keeping the original key is refused by the importer (learner answers reference it).
        $this->putJson("/api/v1/admin/content/drafts/{$draft['id']}", ['payload' => [...$draft['payload'], 'key' => 'es-a1-gustar-mcq', 'questions' => [['p' => 'Me ___ el té.', 'o' => ['gusta', 'gustan', 'gusto']]]]])->assertOk();
        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/check")->assertStatus(422)->assertJsonPath('code', 'invalid_draft');
        $this->putJson("/api/v1/admin/content/drafts/{$draft['id']}", ['payload' => $draft['payload']])->assertOk();
        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/submit")->assertOk();

        $this->actingAsUser($reviewer);
        $this->postJson("/api/v1/admin/content/drafts/{$draft['id']}/approve")->assertOk();
        $this->assertSame('retired', $original->fresh()->review_status);
        $this->assertSame('published', Exercise::where('key', 'es-a1-gustar-mcq-v2')->value('review_status'));
        $this->assertSame(ContentDraft::where('public_id', $draft['id'])->value('exercise_id'), Exercise::where('key', 'es-a1-gustar-mcq-v2')->value('id'));
    }

    public function test_drafts_need_content_permissions(): void
    {
        $this->actingAsUser($this->staff('support'));
        $this->getJson('/api/v1/admin/content/drafts')->assertForbidden();
        $this->postJson('/api/v1/admin/content/drafts', ['language' => 'es', 'payload' => $this->payload()])->assertForbidden();
    }
}
