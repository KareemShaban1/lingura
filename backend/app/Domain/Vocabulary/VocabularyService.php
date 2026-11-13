<?php

namespace App\Domain\Vocabulary;

use App\Domain\Practice\ProgressRecorder;
use App\Domain\Practice\TextNormalizer;
use App\Domain\Shared\DomainError;
use App\Domain\Shared\LearningEventRecorder;
use App\Models\FlashcardReview;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use App\Models\Skill;
use App\Models\UserVocabulary;
use App\Models\VocabularyForm;
use App\Models\VocabularyWord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VocabularyService
{
    /** A card counts as mastered once FSRS expects it to be remembered for three weeks. */
    public const MASTERED_STABILITY_DAYS = 21;

    public function __construct(
        private readonly Fsrs $fsrs,
        private readonly ProgressRecorder $progress,
        private readonly LearningEventRecorder $events,
    ) {}

    public function add(LearnerProfile $profile, VocabularyWord $word, string $source): UserVocabulary
    {
        if ($word->language_id !== $profile->target_language_id) {
            throw new DomainError('This word belongs to a different language.', 'wrong_language', 422);
        }
        $card = UserVocabulary::firstOrCreate(
            ['learner_profile_id' => $profile->id, 'vocabulary_word_id' => $word->id],
            ['source' => $source, 'state' => 'new', 'due_at' => now()],
        );
        if ($card->wasRecentlyCreated) {
            $this->events->record($profile, 'vocabulary.added', $card, metadata: ['source' => $source]);
        }

        return $card;
    }

    /** Automatic extraction: a completed lesson adds its target words to the learner's deck. */
    public function addLessonWords(LearnerProfile $profile, Lesson $lesson): int
    {
        $added = 0;
        foreach ($lesson->words as $word) {
            $added += $this->add($profile, $word, 'lesson')->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }

    /** @return Collection<int, UserVocabulary> */
    public function dueQueue(LearnerProfile $profile, int $limit = 20): Collection
    {
        return UserVocabulary::where('learner_profile_id', $profile->id)
            ->where('due_at', '<=', now())
            ->orderByRaw("CASE state WHEN 'relearning' THEN 0 WHEN 'learning' THEN 1 WHEN 'review' THEN 2 ELSE 3 END")
            ->orderBy('due_at')
            ->with(['word.translations', 'word.examples', 'word.relations'])
            ->limit($limit)
            ->get();
    }

    public function review(UserVocabulary $card, int $rating, ?int $durationMs): UserVocabulary
    {
        if ($rating < 1 || $rating > 4) {
            throw new DomainError('Rating must be between 1 and 4.', 'invalid_rating', 422);
        }

        return DB::transaction(function () use ($card, $rating, $durationMs) {
            $card = UserVocabulary::whereKey($card->id)->lockForUpdate()->first();
            $elapsed = $card->last_reviewed_at ? max(0, $card->last_reviewed_at->diffInSeconds(now()) / 86400) : 0;
            $next = $this->fsrs->review($card->stability, $card->difficulty, $elapsed, $rating);

            FlashcardReview::create([
                'user_vocabulary_id' => $card->id,
                'rating' => $rating,
                'state_before' => $card->state,
                'retrievability' => $next['retrievability'],
                'stability_after' => $next['stability'],
                'difficulty_after' => $next['difficulty'],
                'elapsed_days' => round($elapsed, 3),
                'scheduled_days' => $next['interval_days'],
                'duration_ms' => $durationMs !== null ? min($durationMs, 600_000) : null,
                'reviewed_at' => now(),
            ]);

            $card->update([
                'stability' => $next['stability'],
                'difficulty' => $next['difficulty'],
                'state' => $next['state'],
                'reps' => $card->reps + 1,
                'lapses' => $card->lapses + ($rating === 1 && $card->state === 'review' ? 1 : 0),
                // Failed cards return in 10 minutes within the same session.
                'due_at' => $rating === 1 ? now()->addMinutes(10) : now()->addDays($next['interval_days']),
                'last_reviewed_at' => now(),
            ]);

            $this->progress->increment($card->learnerProfile, Skill::where('slug', Skill::VOCABULARY)->value('id'), ['words_reviewed' => 1]);
            $this->events->record($card->learnerProfile, 'vocabulary.reviewed', $card, value: $rating);

            return $card;
        });
    }

    /** @return array<string, int|float|null> */
    public function summary(LearnerProfile $profile): array
    {
        $base = UserVocabulary::where('learner_profile_id', $profile->id);
        $reviews = FlashcardReview::query()
            ->join('user_vocabulary', 'user_vocabulary.id', '=', 'flashcard_reviews.user_vocabulary_id')
            ->where('user_vocabulary.learner_profile_id', $profile->id)
            ->where('flashcard_reviews.state_before', 'review') // retention is measured on scheduled reviews only
            ->where('flashcard_reviews.reviewed_at', '>=', now()->subDays(30))
            ->selectRaw('count(*) as total, sum(case when rating > 1 then 1 else 0 end) as remembered')
            ->first();

        return [
            'total' => (clone $base)->count(),
            'due_now' => (clone $base)->where('due_at', '<=', now())->count(),
            'new' => (clone $base)->where('state', 'new')->count(),
            'learning' => (clone $base)->whereIn('state', ['learning', 'relearning'])->count(),
            'review' => (clone $base)->where('state', 'review')->count(),
            'mastered' => (clone $base)->where('state', 'review')->where('stability', '>=', self::MASTERED_STABILITY_DAYS)->count(),
            'retention_30d' => $reviews->total ? round($reviews->remembered / $reviews->total, 3) : null,
            'reviews_30d' => (int) $reviews->total,
        ];
    }

    /** Dictionary search in the learner's target language (lemma or any inflected form). */
    public function lookup(LearnerProfile $profile, string $query, int $limit = 20): Collection
    {
        $q = TextNormalizer::normalize($query);
        if ($q === '') {
            return collect();
        }
        $escaped = addcslashes($q, '%_\\');
        $ids = VocabularyForm::where('language_id', $profile->target_language_id)
            ->where('form', 'like', $escaped.'%')->limit(100)->pluck('vocabulary_word_id')->unique();

        return VocabularyWord::whereIn('id', $ids)->where('review_status', 'published')
            ->with(['translations', 'examples', 'relations', 'level'])->orderBy('frequency_rank')->limit($limit)->get();
    }

    /**
     * Automatic word extraction from any learning text: returns the catalogue words found in it,
     * keyed by the surface token so the UI can make them tappable.
     *
     * @return array<string, VocabularyWord>
     */
    public function extract(LearnerProfile $profile, string $text): array
    {
        // Spaced scripts: word tokens. Unspaced scripts (e.g. Chinese): every substring that could be a word;
        // the client segments the text with the longest-match rule over the returned keys.
        $tokens = array_values(array_unique([...TextNormalizer::words($text), ...TextNormalizer::candidates($text)]));
        if ($tokens === []) {
            return [];
        }
        $forms = VocabularyForm::where('language_id', $profile->target_language_id)->whereIn('form', $tokens)->get();
        $words = VocabularyWord::whereIn('id', $forms->pluck('vocabulary_word_id'))->where('review_status', 'published')
            ->with(['translations', 'level'])->get()->keyBy('id');

        $found = [];
        foreach ($forms as $form) {
            if (isset($words[$form->vocabulary_word_id])) {
                $found[$form->form] ??= $words[$form->vocabulary_word_id];
            }
        }

        return $found;
    }
}
