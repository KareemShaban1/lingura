<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Content\ContentDraftService;
use App\Http\Controllers\Controller;
use App\Models\ContentDraft;
use App\Models\Exercise;
use App\Models\Language;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** In-app exercise authoring: content.manage writes drafts; content.review (a different person) publishes them. */
class ContentDraftController extends Controller
{
    public function __construct(private readonly ContentDraftService $drafts) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(ContentDraft::STATUSES)], 'language' => ['nullable', 'string', 'max:10']]);
        $rows = ContentDraft::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('language'), fn ($q, $code) => $q->whereHas('language', fn ($l) => $l->where('code', $code)))
            ->with(['language', 'author', 'reviewer'])->latest('updated_at')->paginate(25);

        return response()->json([
            'data' => collect($rows->items())->map(fn (ContentDraft $d) => $this->row($d, $request->user())),
            'meta' => ['page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function show(Request $request, ContentDraft $draft): JsonResponse
    {
        return response()->json(['data' => $this->detail($draft, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['language' => ['required', 'string', Rule::exists('languages', 'code')->where('is_target_enabled', true)], 'payload' => ['required', 'array']]);
        $draft = $this->drafts->create($request->user(), Language::where('code', $data['language'])->firstOrFail(), $data['payload']);

        return response()->json(['data' => $this->detail($draft, $request->user())], 201);
    }

    public function fromExercise(Request $request, string $exercise): JsonResponse
    {
        $model = Exercise::where('public_id', $exercise)->firstOrFail();
        $draft = $this->drafts->fromExercise($request->user(), $model);

        return response()->json(['data' => $this->detail($draft, $request->user())], 201);
    }

    public function update(Request $request, ContentDraft $draft): JsonResponse
    {
        $data = $request->validate(['payload' => ['required', 'array']]);

        return response()->json(['data' => $this->detail($this->drafts->update($draft, $data['payload']), $request->user())]);
    }

    public function check(ContentDraft $draft): JsonResponse
    {
        return response()->json(['data' => $this->drafts->check($draft)]);
    }

    public function submit(Request $request, ContentDraft $draft): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->drafts->submit($draft), $request->user())]);
    }

    public function approve(Request $request, ContentDraft $draft): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->detail($this->drafts->approve($draft, $request->user(), $data['notes'] ?? null), $request->user())]);
    }

    public function reject(Request $request, ContentDraft $draft): JsonResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $this->detail($this->drafts->reject($draft, $request->user(), $data['notes']), $request->user())]);
    }

    /** @return array<string, mixed> */
    private function row(ContentDraft $d, User $viewer): array
    {
        $d->loadMissing(['language', 'author', 'reviewer']);

        return [
            'id' => $d->public_id, 'key' => $d->exercise_key, 'language' => $d->language->code, 'status' => $d->status,
            'type' => $d->payload['type'] ?? null, 'level' => $d->payload['level'] ?? null, 'title' => $d->payload['title']['en'] ?? null,
            'author' => $d->author?->name, 'reviewer' => $d->reviewer?->name, 'review_notes' => $d->review_notes,
            'is_mine' => $d->author_id === $viewer->id,
            'can_review' => $d->status === 'in_review' && $d->author_id !== $viewer->id && $viewer->hasPermission('content.review'),
            'replaces' => $d->replaces_exercise_id ? Exercise::whereKey($d->replaces_exercise_id)->value('key') : null,
            'updated_at' => $d->updated_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(ContentDraft $d, User $viewer): array
    {
        return [...$this->row($d, $viewer), 'payload' => $d->payload, 'editable' => $d->isEditable(), 'exercise_id' => $d->exercise?->public_id];
    }
}
