<?php

namespace App\Http\Controllers\Api;

use App\Domain\Exams\ExamService;
use App\Domain\Media\MediaUrlService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductionTaskResource;
use App\Models\AudioRecording;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptSection;
use App\Models\ExamForm;
use App\Models\ExamFramework;
use App\Models\ExamItem;
use App\Models\LearnerProfile;
use App\Models\WritingSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    /** @var array<int, array<int, int>> framework id => [form id => index] */
    private array $formOrder = [];

    public function __construct(private readonly ExamService $exams) {}

    public function index(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        $frameworks = ExamFramework::where('language_id', $learnerProfile->target_language_id)->where('is_active', true)
            ->with(['translations', 'sections.skill', 'targetLevel'])->orderBy('position')->get();
        $attempts = ExamAttempt::where('learner_profile_id', $learnerProfile->id)->with('form')->latest('id')->get()->groupBy('form.exam_framework_id');

        return response()->json(['data' => $frameworks->map(fn (ExamFramework $f) => [
            ...$this->framework($f),
            'forms_count' => $f->activeForms()->count(),
            'attempts' => ($attempts->get($f->id) ?? collect())->take(10)->map(fn (ExamAttempt $a) => [
                'id' => $a->public_id, 'status' => $a->status, 'form_number' => $this->formNumber($a), 'started_at' => $a->started_at->toIso8601String(),
                'completed_at' => $a->completed_at?->toIso8601String(), 'overall' => $a->result['overall'] ?? null,
            ])->values(),
        ])]);
    }

    public function start(LearnerProfile $learnerProfile, ExamFramework $framework): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $attempt = $this->exams->start($learnerProfile, $framework);

        return response()->json(['data' => $this->payload($attempt)], $attempt->wasRecentlyCreated ? 201 : 200);
    }

    public function show(ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);

        return response()->json(['data' => $this->payload($this->exams->sweep($attempt))]);
    }

    public function startSection(ExamAttempt $attempt, string $skill): JsonResponse
    {
        $this->authorize('update', $attempt);
        $this->exams->startSection($this->exams->sweep($attempt), $this->section($attempt, $skill));

        return response()->json(['data' => $this->payload($attempt->fresh())]);
    }

    public function answer(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);
        $data = $request->validate(['item_id' => ['required', 'string', 'size:26'], 'option_id' => ['required', 'integer']]);
        $answer = $this->exams->answer($attempt, $data['item_id'], $data['option_id']);

        return response()->json(['data' => ['item_id' => $data['item_id'], 'option_id' => $answer->exam_item_option_id]]); // correctness is revealed only in the review
    }

    public function submit(Request $request, ExamAttempt $attempt, string $skill): JsonResponse
    {
        $this->authorize('update', $attempt);
        $data = $request->validate(['text' => ['nullable', 'string', 'max:8000'], 'recording_id' => ['nullable', 'string', 'size:26']]);
        $recording = isset($data['recording_id'])
            ? AudioRecording::where('public_id', $data['recording_id'])->where('learner_profile_id', $attempt->learner_profile_id)->firstOrFail()
            : null;
        $this->exams->submit($attempt, $this->section($attempt, $skill), ['text' => $data['text'] ?? '', 'recording' => $recording]);

        return response()->json(['data' => $this->payload($attempt->fresh())]);
    }

    public function skip(ExamAttempt $attempt, string $skill): JsonResponse
    {
        $this->authorize('update', $attempt);
        $this->exams->skip($attempt, $this->section($attempt, $skill));

        return response()->json(['data' => $this->payload($attempt->fresh())]);
    }

    /** Answers, correct options, explanations and listening scripts — only once the whole test is finished. */
    public function review(ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);
        abort_unless($attempt->status === 'completed', 409, 'The review is available after the test.');
        $answers = $attempt->sections()->with('answers')->get()->flatMap->answers->keyBy('exam_item_id');
        $items = ExamItem::where('exam_form_id', $attempt->exam_form_id)->with(['options', 'section.skill'])->orderBy('position')->get();

        return response()->json(['data' => $items->map(fn (ExamItem $i) => [
            'id' => $i->public_id, 'skill' => $i->section->skill->slug, 'group' => $i->group_key, 'stimulus' => $i->stimulus_text,
            'prompt' => $i->prompt, 'explanation' => $i->explanation,
            'options' => $i->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'is_correct' => $o->is_correct]),
            'chosen_option_id' => $answers->get($i->id)?->exam_item_option_id,
        ])]);
    }

    public function audio(ExamAttempt $attempt, ExamItem $item, MediaUrlService $media): JsonResponse
    {
        $this->authorize('view', $attempt);
        $open = $item->exam_form_id === $attempt->exam_form_id && $attempt->sections()->where('exam_section_id', $item->exam_section_id)->where('status', 'in_progress')->exists();
        abort_unless($open && $item->audio_path, 404);

        return response()->json(['url' => $media->temporaryUrl($item->audio_path)]);
    }

    private function section(ExamAttempt $attempt, string $skill): ExamAttemptSection
    {
        return $attempt->sections()->whereHas('section.skill', fn ($q) => $q->where('slug', $skill))->with('section.skill')->firstOrFail();
    }

    /** @return array<string, mixed> */
    /** 1-based position of the attempt's form among its framework's forms (stable: ordered by key). */
    private function formNumber(ExamAttempt $attempt): int
    {
        $frameworkId = $attempt->form->exam_framework_id;
        $this->formOrder[$frameworkId] ??= ExamForm::where('exam_framework_id', $frameworkId)->orderBy('key')->pluck('id')->flip()->all();

        return ($this->formOrder[$frameworkId][$attempt->exam_form_id] ?? 0) + 1;
    }

    private function framework(ExamFramework $f): array
    {
        return [
            'slug' => $f->slug, 'exam_name' => $f->exam_name, 'title' => $f->translate('title'), 'description' => $f->translate('description'),
            'disclaimer' => $f->translate('disclaimer'), 'scale' => $f->scale, 'target_level' => $f->targetLevel?->label,
            'sections' => $f->sections->map(fn ($s) => ['skill' => $s->skill->slug, 'minutes' => intdiv($s->duration_seconds, 60)])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(ExamAttempt $attempt): array
    {
        $attempt->loadMissing(['form.framework.translations', 'form.framework.sections.skill', 'form.framework.targetLevel', 'form.framework.language']);
        $framework = $attempt->form->framework;
        $sections = $attempt->sections()->with(['section.skill', 'answers', 'level'])->get()->sortBy('section.position')->values();
        $current = $sections->firstWhere('status', 'in_progress');

        return [
            'id' => $attempt->public_id,
            'status' => $attempt->status,
            'framework' => $this->framework($framework),
            'form_number' => $this->formNumber($attempt),
            'language' => ['code' => $framework->language->code, 'direction' => $framework->language->direction],
            'sections' => $sections->map(fn (ExamAttemptSection $s) => [
                'skill' => $s->section->skill->slug,
                'status' => $s->status,
                'reason' => $s->reason,
                'minutes' => intdiv($s->section->duration_seconds, 60),
                'deadline_at' => $s->deadline_at?->toIso8601String(),
                'remaining_seconds' => $s->status === 'in_progress' ? max(0, now()->diffInSeconds($s->deadline_at, false)) : null,
                'raw' => $s->raw_total !== null ? ['correct' => $s->raw_correct, 'total' => $s->raw_total] : null,
                'level' => $s->level?->label,
                'estimate' => $s->estimate,
                'writing_submission_id' => $s->writing_submission_id ? WritingSubmission::find($s->writing_submission_id)?->public_id : null,
            ]),
            'current' => $current ? $this->current($attempt, $current) : null,
            'result' => $attempt->result,
            'server_time' => now()->toIso8601String(),
        ];
    }

    /** The open section's questions (never the answer key or listening scripts) or its writing/speaking task. */
    private function current(ExamAttempt $attempt, ExamAttemptSection $s): array
    {
        $skill = $s->section->skill->slug;
        $task = $attempt->form->tasks()->where('exam_section_id', $s->exam_section_id)->with('task.level', 'task.language')->first()?->task;
        if ($task) {
            return ['skill' => $skill, 'task' => (new ProductionTaskResource($task))->toArray(request())];
        }
        $chosen = $s->answers->pluck('exam_item_option_id', 'exam_item_id');
        $items = ExamItem::where('exam_form_id', $attempt->exam_form_id)->where('exam_section_id', $s->exam_section_id)->with('options')->orderBy('position')->get();

        return ['skill' => $skill, 'groups' => $items->groupBy('group_key')->map(fn ($group, $key) => [
            'key' => $key,
            'stimulus' => $skill === 'listening' ? null : $group->first()->stimulus_text,
            'has_audio' => $group->first()->audio_path !== null,
            'audio_item_id' => $group->first()->public_id,
            'items' => $group->map(fn (ExamItem $i) => [
                'id' => $i->public_id, 'prompt' => $i->prompt,
                'options' => $i->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label]),
                'chosen_option_id' => $chosen->get($i->id),
            ])->values(),
        ])->values()];
    }
}
