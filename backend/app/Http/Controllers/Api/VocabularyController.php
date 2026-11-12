<?php

namespace App\Http\Controllers\Api;

use App\Domain\Vocabulary\VocabularyService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserVocabularyResource;
use App\Http\Resources\WordResource;
use App\Models\LearnerProfile;
use App\Models\UserVocabulary;
use App\Models\VocabularyCollection;
use App\Models\VocabularyWord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class VocabularyController extends Controller
{
    public function __construct(private readonly VocabularyService $vocabulary) {}

    public function index(Request $request, LearnerProfile $learnerProfile): AnonymousResourceCollection
    {
        $this->authorize('view', $learnerProfile);
        $f = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'due', 'learning', 'mastered'])],
            'collection' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        $cards = UserVocabulary::where('learner_profile_id', $learnerProfile->id)
            ->when(($f['filter'] ?? null) === 'due', fn ($q) => $q->where('due_at', '<=', now()))
            ->when(($f['filter'] ?? null) === 'learning', fn ($q) => $q->whereIn('state', ['new', 'learning', 'relearning']))
            ->when(($f['filter'] ?? null) === 'mastered', fn ($q) => $q->where('state', 'review')->where('stability', '>=', VocabularyService::MASTERED_STABILITY_DAYS))
            ->when($f['collection'] ?? null, fn ($q, $c) => $q->whereHas('collections', fn ($cq) => $cq->whereKey($c)))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->whereHas('word', fn ($w) => $w->where('normalized', 'like', addcslashes(mb_strtolower($term), '%_\\').'%')))
            ->with(['word.translations', 'word.examples', 'word.relations', 'word.level', 'collections'])
            ->orderBy('due_at')
            ->paginate(50);

        return UserVocabularyResource::collection($cards);
    }

    public function summary(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);

        return response()->json(['data' => $this->vocabulary->summary($learnerProfile)]);
    }

    public function queue(Request $request, LearnerProfile $learnerProfile): AnonymousResourceCollection
    {
        $this->authorize('view', $learnerProfile);
        $limit = (int) ($request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']])['limit'] ?? 20);

        return UserVocabularyResource::collection($this->vocabulary->dueQueue($learnerProfile, $limit));
    }

    public function review(Request $request, UserVocabulary $userVocabulary): UserVocabularyResource
    {
        $this->authorize('update', $userVocabulary);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,4'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        return new UserVocabularyResource($this->vocabulary->review($userVocabulary, $data['rating'], $data['duration_ms'] ?? null)->load('word'));
    }

    public function store(Request $request, LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $data = $request->validate([
            'word_id' => ['required', 'integer'],
            'source' => ['nullable', Rule::in(['manual', 'reading', 'exercise'])],
            'collection_id' => ['nullable', 'integer'],
        ]);
        $word = VocabularyWord::findOrFail($data['word_id']);
        $card = $this->vocabulary->add($learnerProfile, $word, $data['source'] ?? 'manual');

        if (isset($data['collection_id'])) {
            $collection = VocabularyCollection::where('learner_profile_id', $learnerProfile->id)->findOrFail($data['collection_id']);
            $collection->items()->syncWithoutDetaching([$card->id => ['created_at' => now()]]);
        }

        return (new UserVocabularyResource($card->load(['word', 'collections'])))->response()->setStatusCode($card->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(UserVocabulary $userVocabulary): JsonResponse
    {
        $this->authorize('delete', $userVocabulary);
        $userVocabulary->delete();

        return response()->json(['message' => 'Removed.']);
    }

    public function lookup(Request $request, LearnerProfile $learnerProfile): AnonymousResourceCollection
    {
        $this->authorize('view', $learnerProfile);
        $q = $request->validate(['q' => ['required', 'string', 'max:60']])['q'];

        return WordResource::collection($this->vocabulary->lookup($learnerProfile, $q));
    }

    /** Tokens of a text that match catalogue words — powers tap-to-translate in readings. */
    public function extract(Request $request, LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        $text = $request->validate(['text' => ['required', 'string', 'max:5000']])['text'];
        $found = $this->vocabulary->extract($learnerProfile, $text);
        $known = UserVocabulary::where('learner_profile_id', $learnerProfile->id)
            ->whereIn('vocabulary_word_id', collect($found)->pluck('id'))->pluck('vocabulary_word_id')->flip();

        return response()->json(['data' => collect($found)->map(fn (VocabularyWord $w) => [
            ...(new WordResource($w))->toArray($request),
            'in_deck' => $known->has($w->id),
        ])]);
    }

    public function collections(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);

        return response()->json(['data' => VocabularyCollection::where('learner_profile_id', $learnerProfile->id)
            ->withCount('items')->orderBy('name')->get(['id', 'name'])]);
    }

    public function createCollection(Request $request, LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $data = $request->validate(['name' => ['required', 'string', 'max:80',
            Rule::unique('vocabulary_collections', 'name')->where('learner_profile_id', $learnerProfile->id)]]);
        $collection = VocabularyCollection::create(['learner_profile_id' => $learnerProfile->id, 'name' => $data['name']]);

        return response()->json(['data' => ['id' => $collection->id, 'name' => $collection->name, 'items_count' => 0]], 201);
    }

    public function deleteCollection(VocabularyCollection $collection): JsonResponse
    {
        $this->authorize('delete', $collection);
        $collection->delete();

        return response()->json(['message' => 'Collection deleted.']);
    }

    public function toggleCollectionItem(Request $request, VocabularyCollection $collection): JsonResponse
    {
        $this->authorize('update', $collection);
        $cardId = (int) $request->validate(['user_vocabulary_id' => ['required', 'integer']])['user_vocabulary_id'];
        UserVocabulary::where('learner_profile_id', $collection->learner_profile_id)->findOrFail($cardId);
        $result = $collection->items()->toggle([$cardId => ['created_at' => now()]]);

        return response()->json(['data' => ['attached' => $result['attached'] !== []]]);
    }
}
