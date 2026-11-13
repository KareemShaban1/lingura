<?php

namespace App\Domain\Content;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainError;
use App\Models\CefrLevel;
use App\Models\ContentDraft;
use App\Models\ContentReview;
use App\Models\Exercise;
use App\Models\GrammarTopic;
use App\Models\Language;
use App\Models\Skill;
use App\Models\User;
use ErrorException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use TypeError;

/**
 * In-app authoring of single exercises, in the same format as content packs. Drafts are checked with the real
 * importer in a rolled-back transaction, go through review by a different person (four-eyes), and are published
 * by importing them for real. Every step is audited. Exercises with learner attempts stay immutable: editing one
 * produces a draft under a new key that retires the original when published.
 */
class ContentDraftService
{
    public function __construct(private readonly ContentPackImporter $importer, private readonly AuditLogger $audit) {}

    public function create(User $author, Language $language, array $payload, ?Exercise $replaces = null): ContentDraft
    {
        $payload = $this->validatePayload($language, $payload);
        $this->assertKeyFree($payload['key']);

        return DB::transaction(function () use ($author, $language, $payload, $replaces) {
            $draft = ContentDraft::create([
                'language_id' => $language->id, 'exercise_key' => $payload['key'], 'payload' => $payload,
                'status' => 'draft', 'author_id' => $author->id, 'replaces_exercise_id' => $replaces?->id,
            ]);
            $this->audit->log('admin.content_draft_created', $draft, new: ['key' => $payload['key']]);

            return $draft;
        });
    }

    public function update(ContentDraft $draft, array $payload): ContentDraft
    {
        $this->assertEditable($draft);
        $payload = $this->validatePayload($draft->language, $payload);
        $this->assertKeyFree($payload['key'], $draft);
        $old = $draft->payload;
        $draft->update(['payload' => $payload, 'exercise_key' => $payload['key'], 'status' => 'draft']);
        $this->audit->log('admin.content_draft_updated', $draft, ['key' => $old['key'] ?? null], ['key' => $payload['key']]);

        return $draft;
    }

    /** Runs the importer on the draft and rolls back: nothing is written. Returns what the import would change. */
    public function check(ContentDraft $draft): array
    {
        $existing = Exercise::withTrashed()->where('key', $draft->exercise_key)->first();
        DB::beginTransaction();
        try {
            $this->importer->import(['language' => $draft->language->code, 'exercises' => [$draft->payload]]);
        } catch (InvalidArgumentException|ModelNotFoundException|ErrorException|TypeError $e) {
            throw new DomainError($e->getMessage(), 'invalid_draft', 422);
        } finally {
            DB::rollBack();
        }

        return ['valid' => true, 'replaces_existing' => $existing !== null, 'needs_audio' => in_array($draft->payload['type'], Exercise::AUDIO_TYPES, true)];
    }

    public function submit(ContentDraft $draft): ContentDraft
    {
        $this->assertEditable($draft);
        $this->check($draft);
        $draft->update(['status' => 'in_review', 'submitted_at' => now(), 'review_notes' => null]);
        $this->audit->log('admin.content_draft_submitted', $draft, new: ['key' => $draft->exercise_key]);

        return $draft;
    }

    public function approve(ContentDraft $draft, User $reviewer, ?string $notes = null): ContentDraft
    {
        $this->assertReviewable($draft, $reviewer);

        try {
            return DB::transaction(function () use ($draft, $reviewer, $notes) {
                $ids = $this->importer->import(['language' => $draft->language->code, 'exercises' => [$draft->payload]]);
                $exercise = Exercise::where('key', $draft->exercise_key)->firstOrFail();
                if ($draft->replaces_exercise_id && $draft->replaces_exercise_id !== $exercise->id) {
                    Exercise::whereKey($draft->replaces_exercise_id)->where('review_status', 'published')
                        ->update(['review_status' => 'retired', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
                }
                $draft->update(['status' => 'published', 'reviewer_id' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $notes, 'exercise_id' => $exercise->id]);
                ContentReview::create(['reviewable_type' => 'exercise', 'reviewable_id' => $exercise->id, 'reviewer_id' => $reviewer->id,
                    'decision' => 'approved', 'notes' => $notes, 'created_at' => now()]);
                $this->audit->log('admin.content_draft_published', $draft, new: ['key' => $draft->exercise_key, 'exercises' => $ids['exercises'], 'replaced' => $draft->replaces_exercise_id]);

                return $draft;
            });
        } catch (InvalidArgumentException|ModelNotFoundException|ErrorException|TypeError $e) {
            // Something changed since review started (e.g. the key gained learner attempts): nothing was written.
            throw new DomainError($e->getMessage(), 'invalid_draft', 422);
        }
    }

    public function reject(ContentDraft $draft, User $reviewer, string $notes): ContentDraft
    {
        $this->assertReviewable($draft, $reviewer);
        $draft->update(['status' => 'rejected', 'reviewer_id' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $notes]);
        $this->audit->log('admin.content_draft_rejected', $draft, new: ['key' => $draft->exercise_key, 'notes' => $notes]);

        return $draft;
    }

    /** A new draft holding an existing exercise's content. Exercises with attempts get a fresh key and are retired on publish. */
    public function fromExercise(User $author, Exercise $exercise): ContentDraft
    {
        $payload = $this->export($exercise);
        $replaces = null;
        if (DB::table('exercise_attempts')->where('exercise_id', $exercise->id)->exists()) {
            $payload['key'] = $this->nextKey($exercise->key);
            $replaces = $exercise;
        }

        return $this->create($author, $exercise->language, $payload, $replaces);
    }

    /** @return array<string, mixed> the exercise in pack format (first option = key; ordering in correct order) */
    public function export(Exercise $exercise): array
    {
        $skillSlugs = Skill::pluck('slug', 'id');
        $exercise->load(['skill', 'level', 'grammarTopic', 'translations', 'objectives.translations', 'questions.options', 'questions.acceptedAnswers']);
        $questions = $exercise->questions->sortBy('position')->map(function ($q) use ($exercise) {
            $out = match ($exercise->type) {
                'cloze' => ['p' => $q->prompt, 'a' => $q->acceptedAnswers->pluck('answer')->all()],
                'dictation' => ['a' => $q->acceptedAnswers->pluck('answer')->all()],
                'repeat' => ['r' => $q->reference_text],
                'ordering' => ['o' => $q->options->sortBy('correct_position')->pluck('label')->all()],
                default => ['p' => $q->prompt, 'o' => $q->options->sortByDesc('is_correct')->pluck('label')->all()],
            };
            if ($q->explanation) {
                $out['e'] = $q->explanation;
            }

            return $out;
        })->values()->all();

        return array_filter([
            'key' => $exercise->key, 'type' => $exercise->type, 'skill' => $exercise->skill->slug, 'level' => $exercise->level->code,
            'topic' => $exercise->grammarTopic?->slug,
            'title' => $exercise->translations->where('field', 'title')->pluck('value', 'locale')->all() ?: null,
            'text' => $exercise->stimulus_text,
            'objectives' => $exercise->objectives->map(fn ($o) => [
                'code' => $o->code, 'skill' => $skillSlugs[$o->skill_id], 'text' => $o->translations->where('field', 'description')->pluck('value', 'locale')->all(),
            ])->values()->all() ?: null,
            'questions' => $questions,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> the payload, trimmed to the pack format */
    public function validatePayload(Language $language, array $payload): array
    {
        $type = $payload['type'] ?? null;
        $choice = in_array($type, ['mcq', 'reading', 'listening'], true);
        $rules = [
            'key' => ['required', 'string', 'max:120', 'regex:/^'.preg_quote($language->code, '/').'-[a-z0-9]+(-[a-z0-9]+)*$/'],
            'type' => ['required', Rule::in(Exercise::TYPES)],
            'skill' => ['required', Rule::in(Skill::pluck('slug')->all())],
            'level' => ['required', Rule::in(CefrLevel::pluck('code')->all())],
            'topic' => ['nullable', Rule::in(GrammarTopic::where('language_id', $language->id)->pluck('slug')->all())],
            'title' => ['nullable', 'array'], // optional, as in packs: the library falls back to the topic title
            'title.en' => ['required_with:title', 'string', 'max:200'],
            'title.ar' => ['nullable', 'string', 'max:200'],
            'text' => [Rule::requiredIf(in_array($type, ['reading', 'listening'], true)), 'nullable', 'string', 'max:5000'],
            'questions' => ['required', 'array', 'min:1', 'max:30'],
            'questions.*.e' => ['nullable', 'string', 'max:1000'],
            'objectives' => ['nullable', 'array', 'max:5'],
            'objectives.*.code' => ['required', 'string', 'max:120', 'regex:/^'.preg_quote($language->code, '/').'\.[a-z0-9_.]+$/'],
            'objectives.*.skill' => ['required', Rule::in(Skill::pluck('slug')->all())],
            'objectives.*.text.en' => ['required', 'string', 'max:300'],
            'objectives.*.text.ar' => ['nullable', 'string', 'max:300'],
        ];
        $rules += match (true) {
            $choice => ['questions.*.p' => ['required', 'string', 'max:500'], 'questions.*.o' => ['required', 'array', 'min:2', 'max:6'], 'questions.*.o.*' => ['required', 'string', 'max:200']],
            $type === 'cloze' => ['questions.*.p' => ['required', 'string', 'max:500'], 'questions.*.a' => ['required', 'array', 'min:1', 'max:5'], 'questions.*.a.*' => ['required', 'string', 'max:200']],
            $type === 'dictation' => ['questions.*.a' => ['required', 'array', 'min:1', 'max:5'], 'questions.*.a.*' => ['required', 'string', 'max:300']],
            $type === 'repeat' => ['questions.*.r' => ['required', 'string', 'max:300']],
            $type === 'ordering' => ['questions.*.o' => ['required', 'array', 'min:2', 'max:15'], 'questions.*.o.*' => ['required', 'string', 'max:60']],
            default => [],
        };
        $data = Validator::make($payload, $rules)->validate();

        $keep = match (true) {
            $choice => ['p', 'o', 'e'], $type === 'cloze' => ['p', 'a', 'e'], $type === 'dictation' => ['a', 'e'],
            $type === 'repeat' => ['r', 'e'], default => ['o', 'e'],
        };
        $data['questions'] = array_map(fn ($q) => array_filter(array_intersect_key($q, array_flip($keep)), fn ($v) => $v !== null && $v !== ''), $data['questions']);
        $data['title'] = array_filter($data['title'] ?? []);
        foreach ($data['objectives'] ?? [] as $i => $o) {
            $data['objectives'][$i]['text'] = array_filter($o['text']);
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== []);
    }

    private function assertKeyFree(string $key, ?ContentDraft $except = null): void
    {
        $open = ContentDraft::where('exercise_key', $key)->whereIn('status', ['draft', 'in_review', 'rejected'])
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))->exists();
        if ($open) {
            throw ValidationException::withMessages(['key' => 'Another open draft already uses this key.']);
        }
    }

    private function nextKey(string $key): string
    {
        $base = preg_replace('/-v\d+$/', '', $key);
        for ($n = 2; ; $n++) {
            $candidate = "{$base}-v{$n}";
            if (! Exercise::withTrashed()->where('key', $candidate)->exists() && ! ContentDraft::where('exercise_key', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    private function assertEditable(ContentDraft $draft): void
    {
        if (! $draft->isEditable()) {
            throw new DomainError('Only drafts and rejected drafts can be changed.', 'draft_locked', 409);
        }
    }

    private function assertReviewable(ContentDraft $draft, User $reviewer): void
    {
        if ($draft->status !== 'in_review') {
            throw new DomainError('This draft is not waiting for review.', 'draft_not_in_review', 409);
        }
        if ($draft->author_id === $reviewer->id) {
            throw new DomainError('Drafts must be reviewed by someone other than their author.', 'self_review', 403);
        }
    }
}
