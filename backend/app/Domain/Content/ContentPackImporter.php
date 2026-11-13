<?php

namespace App\Domain\Content;

use App\Domain\Practice\TextNormalizer;
use App\Domain\Speech\PronunciationLexicon;
use App\Models\CefrLevel;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\GrammarTopic;
use App\Models\Language;
use App\Models\LearningObjective;
use App\Models\Lesson;
use App\Models\Skill;
use App\Models\VocabularyWord;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imports an authored content pack (database/data/content/{lang}.json): grammar topics, vocabulary,
 * exercises and a course. Idempotent — rows are keyed by slug/lemma/key, so re-running updates in place.
 *
 * Authoring conventions: for mcq/reading/listening the FIRST option is the key (positions are shuffled
 * deterministically on import); for ordering, options are listed in the correct order.
 */
class ContentPackImporter
{
    private Language $language;

    /** @var array<string, int> */
    private array $levels;

    /** @var array<string, int> */
    private array $skills;

    public function importFile(string $path): array
    {
        $pack = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $this->import($pack);
    }

    /** @return array{topics:int, words:int, exercises:int, lessons:int} */
    public function import(array $pack): array
    {
        $this->language = Language::where('code', $pack['language'])->firstOrFail();
        $this->levels = CefrLevel::pluck('id', 'code')->all();
        $this->skills = Skill::pluck('id', 'slug')->all();

        return DB::transaction(function () use ($pack) {
            $topics = $this->importTopics($pack['topics'] ?? []);
            $words = $this->importWords($pack['words'] ?? []);
            $exercises = $this->importExercises($pack['exercises'] ?? [], $topics);
            $lessons = isset($pack['course']) ? $this->importCourse($pack['course'], $topics, $words, $exercises) : 0;

            DB::afterCommit(fn () => app(PronunciationLexicon::class)->flush($pack['language']));

            return ['topics' => count($topics), 'words' => count($words), 'exercises' => count($exercises), 'lessons' => $lessons];
        });
    }

    /** @return array<string, int> slug => id */
    private function importTopics(array $topics): array
    {
        $ids = [];
        foreach ($topics as $i => $t) {
            $topic = GrammarTopic::updateOrCreate(
                ['language_id' => $this->language->id, 'slug' => $t['slug']],
                ['cefr_level_id' => $this->level($t['level']), 'position' => $i, 'review_status' => 'published'],
            );
            $topic->setTranslations('title', $t['title']);
            $topic->setTranslations('explanation', $t['explanation']);
            $topic->examples()->delete();
            foreach ($t['examples'] ?? [] as $p => [$sentence, $gloss]) {
                $this->assertScript($sentence, "topic {$t['slug']} example");
                $topic->examples()->create(['sentence' => $sentence, 'translation' => $gloss, 'position' => $p]);
            }
            $ids[$t['slug']] = $topic->id;
        }

        return $ids;
    }

    /** @return array<string, int> lemma => id */
    private function importWords(array $words): array
    {
        $ids = [];
        foreach ($words as $rank => $w) {
            $this->assertScript($w['lemma'], 'word');
            $word = VocabularyWord::updateOrCreate(
                ['language_id' => $this->language->id, 'lemma' => $w['lemma'], 'part_of_speech' => $w['pos']],
                [
                    'normalized' => TextNormalizer::normalize($w['lemma']),
                    'gender' => $w['gender'] ?? null,
                    'cefr_level_id' => $this->level($w['level']),
                    'frequency_rank' => $rank + 1,
                    'ipa' => $w['pron'] ?? null, // IPA, or pinyin with tone marks for Chinese
                    'review_status' => 'published',
                ],
            );
            $word->setTranslations('meaning', $w['meaning']);
            $word->forms()->delete();
            $forms = array_values(array_unique(array_map(
                fn ($f) => TextNormalizer::normalize($f),
                [$w['lemma'], ...($w['forms'] ?? [])],
            )));
            foreach ($forms as $form) {
                $word->forms()->create(['language_id' => $this->language->id, 'form' => $form]);
            }
            $word->examples()->delete();
            if (isset($w['example'])) {
                $word->examples()->create(['sentence' => $w['example'][0], 'translation' => $w['example'][1] ?? null, 'position' => 0]);
            }
            $word->relations()->delete();
            foreach (['syn' => 'synonym', 'ant' => 'antonym'] as $key => $type) {
                foreach ($w[$key] ?? [] as $value) {
                    $word->relations()->create(['type' => $type, 'value' => $value]);
                }
            }
            $ids[$w['lemma']] = $word->id;
        }

        return $ids;
    }

    /** @return array<string, int> key => id */
    private function importExercises(array $exercises, array $topics): array
    {
        $ids = [];
        foreach ($exercises as $e) {
            if (! in_array($e['type'], Exercise::TYPES, true)) {
                throw new InvalidArgumentException("Exercise {$e['key']}: unknown type {$e['type']}");
            }
            if (isset($e['text'])) {
                $this->assertScript($e['text'], "exercise {$e['key']} text");
            }
            $level = CefrLevel::find($this->level($e['level']));
            $existing = Exercise::withTrashed()->where('key', $e['key'])->first();
            $textChanged = $existing && $existing->stimulus_text !== ($e['text'] ?? null);
            $hash = hash('sha256', json_encode([$e['type'], $e['text'] ?? null, $e['questions']], JSON_UNESCAPED_UNICODE));
            $contentChanged = ! $existing || $existing->content_hash !== $hash;

            // Learners' answers reference these questions: published content with attempts is immutable.
            if ($existing && $contentChanged && DB::table('exercise_attempts')->where('exercise_id', $existing->id)->exists()) {
                throw new InvalidArgumentException("Exercise {$e['key']} has learner attempts and cannot be edited; retire it and publish a new key.");
            }

            $exercise = Exercise::withTrashed()->updateOrCreate(['key' => $e['key']], [
                'language_id' => $this->language->id,
                'skill_id' => $this->skill($e['skill']),
                'cefr_level_id' => $level->id,
                'grammar_topic_id' => isset($e['topic']) ? $this->topicId($e['topic'], $topics) : null,
                'type' => $e['type'],
                'difficulty' => $level->anchor_theta,
                'estimated_seconds' => $e['seconds'] ?? 30 * count($e['questions']) + (isset($e['text']) ? 60 : 0),
                'stimulus_text' => $e['text'] ?? null,
                'audio_path' => $textChanged ? null : $existing?->audio_path, // stale audio is regenerated
                'source' => 'authored',
                'content_hash' => $hash,
                // An editor's retirement survives re-imports; only new or active exercises are (re)published.
                'review_status' => $existing?->review_status === 'retired' ? 'retired' : 'published',
                'reviewed_at' => $existing?->reviewed_at ?? now(),
                'deleted_at' => null,
            ]);
            if (isset($e['title'])) {
                $exercise->setTranslations('title', $e['title']);
            }
            foreach ($e['objectives'] ?? [] as $o) {
                $objective = LearningObjective::updateOrCreate(['code' => $o['code']], [
                    'language_id' => $this->language->id, 'skill_id' => $this->skill($o['skill']), 'cefr_level_id' => $level->id,
                ]);
                $objective->setTranslations('description', $o['text']);
                $exercise->objectives()->syncWithoutDetaching([$objective->id]);
            }

            if ($contentChanged) {
                $exercise->questions()->delete();
                foreach ($e['questions'] as $p => $q) {
                    $this->importQuestion($exercise, $p, $q, $e['key']);
                }
            }
            $ids[$e['key']] = $exercise->id;
        }

        return $ids;
    }

    private function importQuestion(Exercise $exercise, int $position, array $q, string $key): void
    {
        $question = $exercise->questions()->create([
            'position' => $position,
            'prompt' => $q['p'] ?? '',
            'explanation' => $q['e'] ?? null,
        ]);

        switch ($exercise->type) {
            case 'cloze':
                if (! str_contains($q['p'], '___') || empty($q['a'])) {
                    throw new InvalidArgumentException("{$key}#{$position}: cloze needs a ___ gap and accepted answers");
                }
                $this->assertScript($q['p'], "{$key}#{$position}");
                foreach ($q['a'] as $answer) {
                    $question->acceptedAnswers()->create(['answer' => $answer]);
                }
                break;
            case 'dictation':
                if (empty($q['a'])) {
                    throw new InvalidArgumentException("{$key}#{$position}: dictation needs the reference sentence");
                }
                $this->assertScript($q['a'][0], "{$key}#{$position}");
                foreach ($q['a'] as $answer) {
                    $question->acceptedAnswers()->create(['answer' => $answer]);
                }
                break;
            case 'repeat':
                if (empty($q['r'])) {
                    throw new InvalidArgumentException("{$key}#{$position}: repeat needs a reference sentence");
                }
                $this->assertScript($q['r'], "{$key}#{$position}");
                $question->update(['reference_text' => $q['r']]);
                break;
            case 'ordering':
                $this->assertOptions($q['o'], "{$key}#{$position}");
                $display = $this->shuffle($q['o'], $key.$position);
                if ($display === $q['o']) {
                    $display = [...array_slice($display, 1), $display[0]]; // never show the answer pre-solved
                }
                foreach ($q['o'] as $correctPosition => $label) {
                    $question->options()->create([
                        'label' => $label,
                        'correct_position' => $correctPosition,
                        'position' => array_search($label, $display, true),
                        'is_correct' => false,
                    ]);
                }
                break;
            default: // mcq, reading, listening — first option is the key
                $this->assertOptions($q['o'], "{$key}#{$position}");
                foreach ($this->shuffle($q['o'], $key.$position) as $displayPosition => $label) {
                    $question->options()->create(['label' => $label, 'position' => $displayPosition, 'is_correct' => $label === $q['o'][0]]);
                }
        }
    }

    private function importCourse(array $c, array $topics, array $words, array $exercises): int
    {
        $course = Course::withTrashed()->updateOrCreate(['slug' => $c['slug']], [
            'language_id' => $this->language->id,
            'level_from_id' => $this->level($c['level_from']),
            'level_to_id' => $this->level($c['level_to']),
            // Courses of a language are listed (and followed) from the lowest starting level up.
            'position' => (int) CefrLevel::whereKey($this->level($c['level_from']))->value('ordinal'),
            'review_status' => 'published',
            'deleted_at' => null,
        ]);
        $course->setTranslations('title', $c['title']);
        $course->setTranslations('description', $c['description'] ?? []);

        $count = 0;
        foreach ($c['units'] as $u => $unitData) {
            $unit = $course->units()->updateOrCreate(['position' => $u], []);
            $unit->setTranslations('title', $unitData['title']);

            foreach ($unitData['lessons'] as $l => $ld) {
                $lesson = Lesson::withTrashed()->updateOrCreate(['key' => $ld['key']], [
                    'course_unit_id' => $unit->id,
                    'position' => $l,
                    'skill_id' => $this->skill($ld['skill']),
                    'cefr_level_id' => $this->level($ld['level']),
                    'grammar_topic_id' => isset($ld['topic']) ? $topics[$ld['topic']] : null,
                    'estimated_minutes' => $ld['minutes'],
                    'review_status' => 'published',
                    'deleted_at' => null,
                ]);
                $lesson->setTranslations('title', $ld['title']);
                $lesson->setTranslations('intro', $ld['intro']);

                $objectiveIds = [];
                foreach ($ld['objectives'] ?? [] as $o) {
                    $objective = LearningObjective::updateOrCreate(['code' => $o['code']], [
                        'language_id' => $this->language->id,
                        'skill_id' => $this->skill($o['skill']),
                        'cefr_level_id' => $this->level($ld['level']),
                    ]);
                    $objective->setTranslations('description', $o['text']);
                    $objectiveIds[] = $objective->id;
                }
                $lesson->objectives()->sync($objectiveIds);

                $lesson->words()->sync(collect($ld['words'] ?? [])->mapWithKeys(fn ($lemma, $i) => [
                    ($words[$lemma] ?? throw new InvalidArgumentException("Lesson {$ld['key']}: unknown word {$lemma}")) => ['position' => $i],
                ])->all());
                $lesson->exercises()->sync(collect($ld['exercises'] ?? [])->mapWithKeys(fn ($key, $i) => [
                    ($exercises[$key] ?? throw new InvalidArgumentException("Lesson {$ld['key']}: unknown exercise {$key}")) => ['position' => $i],
                ])->all());

                // Exercises inherit the lesson's objectives so every exercise has explicit learning objectives.
                foreach ($lesson->exercises as $exercise) {
                    $exercise->objectives()->syncWithoutDetaching($objectiveIds);
                }
                $count++;
            }
        }

        return $count;
    }

    private function assertOptions(array $options, string $where): void
    {
        // Exact comparison: options may legitimately differ only in vowel marks (e.g. Arabic case endings).
        $normalised = array_map(fn ($o) => mb_strtolower(\Normalizer::normalize(trim($o), \Normalizer::FORM_C)), $options);
        if (count($options) < 2 || count(array_unique($normalised)) !== count($normalised)) {
            throw new InvalidArgumentException("{$where}: needs at least two distinct options");
        }
    }

    private function assertScript(string $text, string $where): void
    {
        $pattern = match ($this->language->script) {
            'Arab' => '/\p{Arabic}/u',
            'Latn' => '/\p{Latin}/u',
            'Hans', 'Hant' => '/\p{Han}/u',
            default => null,
        };
        // Bracketed hints ("(xuéxí)", "(to study)") may use another script.
        $letters = preg_replace('/[^\p{L}]/u', '', preg_replace('/\([^)]*\)/u', '', $text));
        if ($pattern && $letters !== '' && preg_match_all($pattern, $letters) / mb_strlen($letters) < 0.6) {
            throw new InvalidArgumentException("{$where}: text is not in the {$this->language->script} script");
        }
    }

    /** Deterministic shuffle so imports are reproducible. @return list<string> */
    private function shuffle(array $items, string $seed): array
    {
        usort($items, fn ($a, $b) => strcmp(hash('sha256', $seed.$a), hash('sha256', $seed.$b)));

        return $items;
    }

    /** A topic from this pack, or (for single-exercise drafts) one already published for the language. */
    private function topicId(string $slug, array $topics): int
    {
        return $topics[$slug]
            ?? GrammarTopic::where('language_id', $this->language->id)->where('slug', $slug)->value('id')
            ?? throw new InvalidArgumentException("Unknown topic {$slug}");
    }

    private function level(string $code): int
    {
        return $this->levels[$code] ?? throw new InvalidArgumentException("Unknown level {$code}");
    }

    private function skill(string $slug): int
    {
        return $this->skills[$slug] ?? throw new InvalidArgumentException("Unknown skill {$slug}");
    }
}
