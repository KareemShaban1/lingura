"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, CircleAlert, RotateCcw, XCircle } from "lucide-react";
import { Choice } from "@/components/choice";
import { SkillIcon } from "@/components/skill";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Progress } from "@/components/ui/progress";
import { ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { startExerciseAttempt, useActiveProfile, useInvalidateProfile } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import type { AnswerFeedback, ExerciseAttempt } from "@/lib/types";
import { cn } from "@/lib/utils";
import { PronunciationView } from "@/features/speech/pronunciation-view";
import { Recorder } from "@/features/speech/recorder";
import { AudioPlayer } from "./audio-player";
import { TappableText } from "./tappable-text";
import { useActivitySession } from "./use-activity-session";
import { toastError } from "@/lib/errors";

type Question = ExerciseAttempt["exercise"]["questions"][number];

function FeedbackPanel({ feedback, lang }: { feedback: AnswerFeedback; lang: { code: string; direction: "ltr" | "rtl" } }) {
  const { t, f } = useI18n();
  const tone = feedback.is_correct ? "correct" : feedback.score > 0 ? "partial" : "incorrect";
  const Icon = tone === "correct" ? CheckCircle2 : tone === "partial" ? CircleAlert : XCircle;
  return (
    <div role="status" aria-live="polite" className={cn("rounded-xl border p-4 text-sm",
      tone === "correct" && "border-success/40 bg-success/10", tone === "partial" && "border-warning/50 bg-warning/10", tone === "incorrect" && "border-destructive/40 bg-destructive/5")}>
      <p className="flex items-center gap-2 font-semibold">
        <Icon className={cn("size-4", tone === "correct" ? "text-success" : tone === "partial" ? "text-warning" : "text-destructive")} aria-hidden />
        {t.exercise[tone]}
      </p>
      {feedback.feedback_code && (
        <p className="mt-1">{f(t.exercise.feedback[feedback.feedback_code], { score: Math.round(feedback.score * 100) })}</p>
      )}
      {feedback.pronunciation && (
        <div className="mt-3 border-t pt-3"><PronunciationView result={feedback.pronunciation} lang={lang.code} dir={lang.direction} /></div>
      )}
      {!feedback.is_correct && feedback.correct_answer && !feedback.pronunciation && (
        <p className="mt-2"><span className="text-muted-foreground">{t.exercise.correctAnswer}: </span><strong dir="auto">{feedback.correct_answer}</strong></p>
      )}
      {feedback.explanation && <p className="mt-2 text-muted-foreground">{feedback.explanation}</p>}
    </div>
  );
}

/** Builds the payload for the current question type; returns null while the learner hasn't answered. */
function QuestionInput({ attempt, question, onChange, disabled, profileId }: {
  attempt: ExerciseAttempt; question: Question; disabled: boolean; profileId: string;
  onChange: (payload: Record<string, unknown> | null) => void;
}) {
  const { t } = useI18n();
  const { type, language } = attempt.exercise;
  const [option, setOption] = useState<number | null>(question.answer?.selected_option_id ?? null);
  const [text, setText] = useState(question.answer?.given ?? "");
  const [order, setOrder] = useState<number[]>([]);
  const ref = useRef<HTMLInputElement>(null);

  useEffect(() => ref.current?.focus(), []);

  if (type === "repeat") {
    return (
      <div className="space-y-5 text-center">
        <p lang={language.code} dir={language.direction} className="font-display text-3xl leading-snug">{question.reference_text}</p>
        {question.has_audio && <div className="flex justify-center"><AudioPlayer compact src={`/exercise-attempts/${attempt.id}/audio?question_id=${question.id}`} /></div>}
        {!disabled && (
          <Recorder profileId={profileId} purpose="pronunciation" onRecorded={(id) => onChange({ recording_id: id })} />
        )}
      </div>
    );
  }

  if (type === "cloze" || type === "dictation") {
    const parts = question.prompt.split("___");
    const input = (
      <Input ref={ref} dir={language.direction} lang={language.code} value={text} disabled={disabled} placeholder={t.exercise.typeHere}
        className={cn(type === "cloze" ? "mx-1 inline-flex h-11 w-40 text-lg" : "h-12 text-lg")} autoComplete="off" autoCapitalize="off" spellCheck={false}
        onChange={(e) => { setText(e.target.value); onChange(e.target.value.trim() ? { text: e.target.value } : null); }} />
    );
    if (type === "dictation") {
      return (
        <div className="space-y-4">
          <AudioPlayer src={`/exercise-attempts/${attempt.id}/audio?question_id=${question.id}`} />
          {input}
        </div>
      );
    }
    return (
      <p lang={language.code} dir={language.direction} className="text-xl leading-loose md:text-2xl">
        {parts[0]}{input}{parts.slice(1).join("___")}
      </p>
    );
  }

  if (type === "ordering") {
    const chosen = order.map((id) => question.options.find((o) => o.id === id)!);
    const pool = question.options.filter((o) => !order.includes(o.id));
    const update = (next: number[]) => { setOrder(next); onChange(next.length === question.options.length ? { order: next } : null); };
    return (
      <div className="space-y-4" lang={language.code} dir={language.direction}>
        <div className="flex min-h-14 flex-wrap items-center gap-2 rounded-xl border-2 border-dashed p-3" aria-label="answer">
          {chosen.map((o) => (
            <button key={o.id} type="button" disabled={disabled} onClick={() => update(order.filter((id) => id !== o.id))}
              className="rounded-lg bg-primary px-3 py-2 text-lg text-primary-foreground">{o.label}</button>
          ))}
        </div>
        <div className="flex flex-wrap gap-2">
          {pool.map((o) => (
            <button key={o.id} type="button" disabled={disabled} onClick={() => update([...order, o.id])}
              className="rounded-lg border bg-card px-3 py-2 text-lg hover:border-primary">{o.label}</button>
          ))}
        </div>
        {!disabled && order.length > 0 && <Button variant="ghost" size="sm" onClick={() => update([])}><RotateCcw /> {t.exercise.reset}</Button>}
      </div>
    );
  }

  // mcq, reading, listening
  const answered = question.answer;
  return (
    <div className="space-y-4">
      {type === "mcq" ? null : <p lang={language.code} dir={language.direction} className="text-lg font-medium">{question.prompt}</p>}
      {type === "mcq" && <PromptWithGap text={question.prompt} lang={language.code} dir={language.direction} />}
      <div role="radiogroup" className="grid gap-2.5">
        {question.options.map((o) => (
          <Choice key={o.id} lang={language.code} dir={language.direction} disabled={disabled}
            selected={option === o.id} title={o.label}
            onSelect={() => { setOption(o.id); onChange({ option_id: o.id }); }}
            aside={answered && o.id === answered.correct_option_id ? <CheckCircle2 className="size-5 text-success" /> : null} />
        ))}
      </div>
    </div>
  );
}

function PromptWithGap({ text, lang, dir }: { text: string; lang: string; dir: "ltr" | "rtl" }) {
  const parts = text.split("___");
  return (
    <p lang={lang} dir={dir} className="text-xl leading-relaxed md:text-2xl">
      {parts.map((p, i) => <span key={i}>{p}{i < parts.length - 1 && <span className="mx-1 inline-block w-16 border-b-2 border-primary align-baseline" />}</span>)}
    </p>
  );
}

function Results({ attempt, backHref, backLabel, onRetry }: { attempt: ExerciseAttempt; backHref: string; backLabel: string; onRetry: () => void }) {
  const { t, f } = useI18n();
  const { exercise } = attempt;
  return (
    <div className="space-y-6">
      <div className="text-center">
        <CheckCircle2 className="mx-auto size-10 text-success" aria-hidden />
        <h1 className="mt-3 font-display text-3xl">{t.exercise.resultTitle}</h1>
        <p className="mt-1 text-muted-foreground">{exercise.title ?? t.practice.types[exercise.type]}</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <Card><CardContent className="p-5"><p className="text-xs text-muted-foreground">{t.exercise.score}</p><p className="font-display text-4xl">{Math.round((attempt.score ?? 0) * 100)}%</p></CardContent></Card>
        {attempt.words_per_minute && (
          <Card><CardContent className="p-5"><p className="text-xs text-muted-foreground">{t.exercise.readingSpeed}</p><p className="font-display text-4xl">{f(attempt.reading_unit === "characters" ? t.exercise.cpm : t.exercise.wpm, { n: attempt.words_per_minute })}</p></CardContent></Card>
        )}
      </div>
      {exercise.type === "listening" && exercise.stimulus && (
        <Card><CardHeader><CardTitle>{t.exercise.transcript}</CardTitle></CardHeader>
          <CardContent><p lang={exercise.language.code} dir={exercise.language.direction} className="text-lg leading-relaxed">{exercise.stimulus}</p></CardContent></Card>
      )}
      <Card>
        <CardHeader><CardTitle>{t.exercise.review}</CardTitle></CardHeader>
        <CardContent>
          <ol className="space-y-3">
            {exercise.questions.map((q, i) => (
              <li key={q.id} className="flex gap-3 text-sm">
                {q.answer?.is_correct ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" /> : <XCircle className="mt-0.5 size-4 shrink-0 text-destructive" />}
                <div dir="auto">
                  <p className="font-medium">{q.prompt || `${i + 1}.`}</p>
                  {q.answer && !q.answer.is_correct && (
                    <p className="text-muted-foreground">{t.exercise.yourAnswer}: {q.answer.given ?? "—"} · {t.exercise.correctAnswer}: <strong className="text-foreground">{q.answer.correct_answer}</strong></p>
                  )}
                  {q.answer?.explanation && !q.answer.is_correct && <p className="text-muted-foreground">{q.answer.explanation}</p>}
                </div>
              </li>
            ))}
          </ol>
        </CardContent>
      </Card>
      <div className="flex flex-wrap gap-3">
        <Button asChild size="lg"><Link href={backHref}>{backLabel}</Link></Button>
        <Button size="lg" variant="outline" onClick={onRetry}><RotateCcw /> {t.exercise.tryAgain}</Button>
      </div>
    </div>
  );
}

export function ExercisePlayer() {
  const { exerciseId } = useParams<{ exerciseId: string }>();
  const params = useSearchParams();
  const lessonId = params.get("lesson");
  const planSession = params.get("session");
  const { t, f } = useI18n();
  const profile = useActiveProfile();
  const qc = useQueryClient();
  const invalidate = useInvalidateProfile();
  // Exercises inside a lesson are timed by the lesson's session.
  const session = useActivitySession(profile?.id, "exercise", planSession, !lessonId);

  const [attempt, setAttempt] = useState<ExerciseAttempt | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [index, setIndex] = useState(0);
  const [payload, setPayload] = useState<Record<string, unknown> | null>(null);
  const [busy, setBusy] = useState(false);
  const [round, setRound] = useState(0);
  const shownAt = useRef(0);
  const readingStart = useRef(0);
  const readingSeconds = useRef<number | null>(null);

  useEffect(() => {
    if (!profile) return;
    let cancelled = false;
    startExerciseAttempt(profile.id, exerciseId, { lessonId, sessionId: session.sessionId ?? planSession })
      .then((a) => {
        if (cancelled) return;
        setAttempt(a);
        const firstOpen = a.exercise.questions.findIndex((q) => !q.answer);
        setIndex(firstOpen === -1 ? a.exercise.questions.length - 1 : firstOpen);
        readingStart.current = Date.now();
        readingSeconds.current = null;
      })
      .catch((e) => !cancelled && setError(e));
    return () => { cancelled = true; };
    // session id is attached when available; starting does not wait for it
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [profile?.id, exerciseId, lessonId, round]);

  useEffect(() => { shownAt.current = Date.now(); }, [index, attempt?.id]);

  const backHref = lessonId ? `/lessons/${lessonId}` : planSession ? "/learning" : "/practice";
  const backLabel = lessonId ? t.exercise.backToLesson : planSession ? t.exercise.backToPlan : t.exercise.backToPractice;
  const question = attempt?.exercise.questions[index];
  const feedback = question?.answer ?? null;
  const total = attempt?.exercise.questions.length ?? 0;
  const answeredCount = useMemo(() => attempt?.exercise.questions.filter((q) => q.answer).length ?? 0, [attempt]);

  async function check() {
    if (!attempt || !question || !payload) return;
    setBusy(true);
    try {
      if (attempt.exercise.type === "reading" && readingSeconds.current === null) {
        readingSeconds.current = Math.round((Date.now() - readingStart.current) / 1000);
      }
      const { data } = await api<{ data: AnswerFeedback }>(`/exercise-attempts/${attempt.id}/answers`, {
        method: "POST", body: { question_id: question.id, ...payload, response_time_ms: Date.now() - shownAt.current },
      });
      setAttempt({ ...attempt, exercise: { ...attempt.exercise, questions: attempt.exercise.questions.map((q) => (q.id === question.id ? { ...q, answer: data } : q)) } });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  async function next() {
    if (!attempt) return;
    setPayload(null);
    if (index < total - 1) {
      setIndex(index + 1);
      return;
    }
    setBusy(true);
    try {
      const { data } = await api<{ data: ExerciseAttempt }>(`/exercise-attempts/${attempt.id}/finish`, {
        method: "POST", body: { reading_seconds: readingSeconds.current },
      });
      if (!lessonId) await session.complete();
      setAttempt(data);
      invalidate(profile!.id);
      qc.invalidateQueries({ queryKey: ["profile", profile!.id] });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  if (error) return <ErrorState error={error} />;
  if (!attempt || !question) return <div className="space-y-4"><Skeleton className="h-8 w-1/2" /><Skeleton className="h-40" /><Skeleton className="h-32" /></div>;

  if (attempt.status === "completed") {
    return <Results attempt={attempt} backHref={backHref} backLabel={backLabel} onRetry={() => { setAttempt(null); setRound((r) => r + 1); }} />;
  }

  const { exercise } = attempt;
  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div className="flex items-center gap-3">
        <SkillIcon skill={exercise.skill} />
        <div className="min-w-0 flex-1">
          <p className="truncate font-medium">{exercise.title ?? t.practice.types[exercise.type]}</p>
          <p className="text-xs text-muted-foreground">{t.practice.types[exercise.type]} · {exercise.level.label}</p>
        </div>
        <Badge variant="outline">{f(t.exercise.question, { n: index + 1, total })}</Badge>
      </div>
      <Progress value={(answeredCount / total) * 100} />
      <p className="text-sm text-muted-foreground">{t.exercise.instructions[exercise.type]}</p>

      {exercise.type === "listening" && exercise.has_audio && <AudioPlayer src={`/exercise-attempts/${attempt.id}/audio`} />}
      {exercise.type === "reading" && exercise.stimulus && profile && (
        <TappableText text={exercise.stimulus} profileId={profile.id} lang={exercise.language.code} dir={exercise.language.direction} />
      )}

      <div key={question.id} className="space-y-5 motion-safe:animate-[fadeIn_200ms_ease-out]">
        {profile && <QuestionInput attempt={attempt} question={question} disabled={!!feedback} onChange={setPayload} profileId={profile.id} />}
        {feedback && <FeedbackPanel feedback={feedback} lang={exercise.language} />}
      </div>

      <div className="flex justify-end">
        {feedback ? (
          <Button size="lg" onClick={next} loading={busy}>{index < total - 1 ? t.exercise.next : t.exercise.finish}</Button>
        ) : (
          <Button size="lg" onClick={check} disabled={!payload} loading={busy}>{t.exercise.check}</Button>
        )}
      </div>
    </div>
  );
}
