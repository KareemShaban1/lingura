<?php

namespace Tests\Feature;

use App\Domain\Content\ContentPackImporter;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\Lesson;
use App\Models\VocabularyWord;
use InvalidArgumentException;
use Tests\TestCase;

class ContentImportTest extends TestCase
{
    public function test_all_six_packs_are_imported_with_complete_exercise_metadata(): void
    {
        $this->assertSame(6, Course::count());
        $this->assertSame(24, Lesson::count());
        $this->assertSame(60, Exercise::count());

        // Every exercise has a language, level, skill, objectives, questions and a way to be scored.
        foreach (Exercise::with(['questions.options', 'questions.acceptedAnswers', 'objectives'])->get() as $e) {
            $this->assertNotNull($e->cefr_level_id);
            $this->assertNotEmpty($e->questions, $e->key);
            $this->assertNotEmpty($e->objectives, "{$e->key} has learning objectives");
            foreach ($e->questions as $q) {
                $scorable = match ($e->type) {
                    'cloze', 'dictation' => $q->acceptedAnswers->isNotEmpty(),
                    'ordering' => $q->options->whereNotNull('correct_position')->count() === $q->options->count(),
                    default => $q->options->where('is_correct', true)->count() === 1,
                };
                $this->assertTrue($scorable, "{$e->key}#{$q->position} is scorable");
            }
        }
    }

    public function test_ordering_questions_are_never_displayed_presolved(): void
    {
        foreach (Exercise::where('type', 'ordering')->with('questions.options')->get() as $e) {
            foreach ($e->questions as $q) {
                $this->assertNotSame(
                    $q->options->sortBy('correct_position')->pluck('id')->all(),
                    $q->options->sortBy('position')->pluck('id')->all(),
                );
            }
        }
    }

    public function test_reimport_is_idempotent_and_published_content_with_attempts_is_immutable(): void
    {
        $importer = app(ContentPackImporter::class);
        $path = database_path('data/content/es.json');
        $importer->importFile($path);
        $this->assertSame(60, Exercise::count());
        $this->assertSame(24, VocabularyWord::where('language_id', Exercise::where('key', 'es-a1-ser-cloze')->value('language_id'))->count());

        $profile = $this->makeLearner('es');
        ExerciseAttempt::create([
            'learner_profile_id' => $profile->id, 'exercise_id' => Exercise::where('key', 'es-a1-ser-cloze')->value('id'),
            'questions_total' => 4, 'started_at' => now(),
        ]);
        $importer->importFile($path); // unchanged content: fine

        $pack = json_decode(file_get_contents($path), true);
        $pack['exercises'][0]['questions'][0]['p'] = 'Tú ___ de México.';
        $this->expectException(InvalidArgumentException::class);
        $importer->import($pack);
    }

    public function test_invalid_content_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ContentPackImporter::class)->import(['language' => 'es', 'exercises' => [
            ['key' => 'bad-1', 'type' => 'cloze', 'skill' => 'grammar', 'level' => 'A1', 'questions' => [['p' => 'No gap here', 'a' => ['x']]]],
        ]]);
    }
}
