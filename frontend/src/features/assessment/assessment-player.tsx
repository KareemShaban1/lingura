"use client";

import { useEffect, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, CircleDashed, Headphones, Info, Loader2, MinusCircle, Play } from "lucide-react";
import { Choice } from "@/components/choice";
import { SkillIcon } from "@/components/skill";
import { Button } from "@/components/ui/button";
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Progress } from "@/components/ui/progress";
import { ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { keys, useAttempt, useNextItem } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { Recorder } from "@/features/speech/recorder";
import { useActiveProfile } from "@/features/queries";
import type { AssessmentAttempt, AssessmentItem, Direction, ProductionTask } from "@/lib/types";
import { cn } from "@/lib/utils";
import { toastError } from "@/lib/errors";
import { countUnits } from "@/lib/text";

/** Renders a cloze prompt with a visible gap instead of raw underscores. */
function Prompt({ text, lang, dir }: { text: string; lang: string; dir: Direction }) {
  const parts = text.split("___");
  return (
    <p lang={lang} dir={dir} className="text-xl leading-relaxed md:text-2xl">
      {parts.map((part, i) => (
        <span key={i}>
          {part}
          {i < parts.length - 1 && <span className="mx-1 inline-block w-16 border-b-2 border-primary align-baseline" aria-label="blank" />}
        </span>
      ))}
    </p>
  );
}

function AudioPlayer({ attemptId, itemId }: { attemptId: string; itemId: string }) {
  const { t } = useI18n();
  const audio = useRef<HTMLAudioElement | null>(null);
  const [url, setUrl] = useState<string | null>(null);
  const [plays, setPlays] = useState(0);
  const [loading, setLoading] = useState(false);

  async function play() {
    try {
      setLoading(true);
      // Signed URLs expire after a few minutes: fetch a fresh one on first play.
      const src = url ?? (await api<{ url: string }>(`/assessment-attempts/${attemptId}/items/${itemId}/audio`)).url;
      setUrl(src);
      if (!audio.current) audio.current = new Audio();
      if (audio.current.src !== src) audio.current.src = src;
      audio.current.currentTime = 0;
      await audio.current.play();
      setPlays((p) => p + 1);
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => () => audio.current?.pause(), []);

  return (
    <div className="flex items-center gap-4 rounded-xl border bg-secondary/40 p-4">
      <span className="grid size-12 place-items-center rounded-full bg-primary text-primary-foreground">
        <Headphones className="size-5" aria-hidden />
      </span>
      <Button variant="outline" onClick={play} loading={loading}>
        <Play className="rtl:rotate-180" /> {plays ? t.assessment.listenAgain : t.assessment.listen}
      </Button>
    </div>
  );
}

function SectionRail({ attempt }: { attempt: AssessmentAttempt }) {
  const { t } = useI18n();
  return (
    <ol className="flex gap-2 overflow-x-auto pb-1" aria-label={t.assessment.section}>
      {attempt.sections.map((s) => {
        const Icon = s.status === "completed" ? CheckCircle2 : s.status === "skipped" || s.status === "not_available" ? MinusCircle : CircleDashed;
        return (
          <li
            key={s.skill}
            aria-current={s.status === "in_progress" ? "step" : undefined}
            title={s.reason ? t.assessment.reasons[s.reason as keyof typeof t.assessment.reasons] ?? s.reason : t.assessment.sectionStates[s.status]}
            className={cn(
              "flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 text-xs",
              s.status === "in_progress" && "border-primary bg-secondary text-secondary-foreground",
              s.status === "completed" && "text-success",
              (s.status === "not_available" || s.status === "skipped") && "text-muted-foreground line-through decoration-1",
            )}
          >
            <Icon className="size-3.5" aria-hidden />
            {t.skills[s.skill]}
          </li>
        );
      })}
    </ol>
  );
}

/** Writing or speaking section: one open task, rubric-scored on submission. */
function ProductiveTask({ attemptId, task, lang, onDone }: { attemptId: string; task: ProductionTask & { skill: "writing" | "speaking" }; lang: { code: string; direction: Direction }; onDone: () => Promise<void> }) {
  const { t, f } = useI18n();
  const profile = useActiveProfile();
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const words = countUnits(text);
  const chars = task.count_unit === "characters";

  async function submit(body: Record<string, unknown>) {
    setBusy(true);
    try {
      await api(`/assessment-attempts/${attemptId}/${task.skill}`, { method: "POST", body });
      await onDone();
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      <p lang={lang.code} dir={lang.direction} className="rounded-xl border bg-secondary/30 p-4 text-lg leading-relaxed">{task.prompt}</p>
      <p className="text-sm text-muted-foreground">
        {f(task.skill === "writing" ? t.placement.writingIntro : t.placement.speakingIntro, { target: f(chars ? t.writing.targetChars : t.writing.target, { min: task.min_words, max: task.max_words }) })}
      </p>
      {busy ? (
        <p role="status" className="flex items-center gap-2 text-muted-foreground"><Loader2 className="size-4 animate-spin" /> {t.placement.evaluating}</p>
      ) : task.skill === "writing" ? (
        <>
          <textarea value={text} onChange={(e) => setText(e.target.value)} rows={8} lang={lang.code} dir={lang.direction} aria-label={t.placement.writingTitle}
            className="w-full rounded-xl border border-input bg-card p-4 text-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40" />
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted-foreground">{f(chars ? t.writing.characters : t.writing.words, { n: words })}</span>
            <Button size="lg" onClick={() => submit({ text })} disabled={words < 3}>{t.placement.submit}</Button>
          </div>
        </>
      ) : profile && (
        <Recorder profileId={profile.id} purpose="speaking_assessment" onRecorded={(id) => submit({ recording_id: id })} />
      )}
    </div>
  );
}

export function AssessmentPlayer() {
  const { attemptId } = useParams<{ attemptId: string }>();
  const { t, f } = useI18n();
  const router = useRouter();
  const qc = useQueryClient();
  const attempt = useAttempt(attemptId);
  const answeredTotal = attempt.data?.sections.reduce((n, s) => n + s.items_answered, 0) ?? 0;
  const [started, setStarted] = useState(false);
  const inProgress = attempt.data?.status === "in_progress";
  const next = useNextItem(attemptId, !!attempt.data && inProgress && (started || answeredTotal > 0));
  // Selection is tied to the item it was made for, so a new item starts unselected without an effect.
  const [selection, setSelection] = useState<{ itemId: string; optionId: number } | null>(null);
  const [busy, setBusy] = useState(false);
  const shownAt = useRef<number>(0);

  const item: AssessmentItem | null | undefined = next.data?.item;
  const live = next.data?.attempt ?? attempt.data;
  const section = live?.sections.find((s) => s.skill === item?.skill);

  const selected = item && selection?.itemId === item.id ? selection.optionId : null;
  const setSelected = (optionId: number) => item && setSelection({ itemId: item.id, optionId });

  useEffect(() => {
    shownAt.current = performance.now();
  }, [item?.id]);

  useEffect(() => {
    if (next.data?.done || attempt.data?.status === "completed") router.replace(`/assessment/${attemptId}/results`);
  }, [next.data?.done, attempt.data?.status, attemptId, router]);

  async function refresh() {
    await qc.invalidateQueries({ queryKey: keys.attemptNext(attemptId) });
  }

  async function submit() {
    if (!item || selected === null) return;
    setBusy(true);
    try {
      await api(`/assessment-attempts/${attemptId}/answers`, {
        method: "POST",
        body: { item_id: item.id, option_id: selected, response_time_ms: Math.round(performance.now() - shownAt.current) },
      });
      await refresh();
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  async function skipSection() {
    if (!item) return;
    setBusy(true);
    try {
      await api(`/assessment-attempts/${attemptId}/sections/${item.skill}/skip`, { method: "POST" });
      await refresh();
    } finally {
      setBusy(false);
    }
  }

  async function finishNow() {
    setBusy(true);
    try {
      await api(`/assessment-attempts/${attemptId}/complete`, { method: "POST" });
      router.replace(`/assessment/${attemptId}/results`);
    } catch (e) {
      toastError(e, t.common.errorTitle);
      setBusy(false);
    }
  }

  if (attempt.error) return <ErrorState error={attempt.error} onRetry={() => attempt.refetch()} />;
  if (!attempt.data) return <Skeleton className="h-80" />;
  const lang = attempt.data.target_language;

  if (inProgress && !started && answeredTotal === 0) {
    return (
      <div className="space-y-6">
        <p className="text-sm font-medium uppercase tracking-[0.14em] text-primary">{lang.name}</p>
        <h1 className="font-display text-4xl">{t.assessment.title}</h1>
        <p className="text-lg text-muted-foreground">{t.assessment.intro}</p>
        <SectionRail attempt={attempt.data} />
        <div className="flex items-start gap-2 rounded-lg bg-muted p-3 text-sm text-muted-foreground">
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden /> {t.common.estimateNotice}
        </div>
        <Button size="lg" onClick={() => setStarted(true)}>{t.assessment.start}</Button>
      </div>
    );
  }

  if (next.error) return <ErrorState error={next.error} onRetry={() => next.refetch()} />;
  const task = next.data?.task;
  if (task && live) {
    return (
      <div className="space-y-6">
        <SectionRail attempt={live} />
        <div className="flex items-center gap-3">
          <SkillIcon skill={task.skill} />
          <p className="font-medium">{t.skills[task.skill]}</p>
        </div>
        <ProductiveTask attemptId={attemptId} task={task} lang={lang} onDone={refresh} />
        <div className="flex gap-2">
          <Button variant="ghost" size="sm" onClick={async () => { await api(`/assessment-attempts/${attemptId}/sections/${task.skill}/skip`, { method: "POST" }); await refresh(); }}>{t.assessment.skipSection}</Button>
        </div>
      </div>
    );
  }
  if (!item || !live) {
    return (
      <div className="space-y-4" role="status" aria-live="polite">
        <Skeleton className="h-6 w-1/3" />
        <Skeleton className="h-24" />
        <p className="text-sm text-muted-foreground">{next.data?.done ? t.assessment.finishing : t.common.loading}</p>
      </div>
    );
  }

  const questionNumber = (section?.items_answered ?? 0) + 1;
  const sectionPct = section ? (section.items_answered / section.max_items) * 100 : 0;

  return (
    <div className="space-y-6">
      <SectionRail attempt={live} />
      <div className="flex items-center gap-3">
        <SkillIcon skill={item.skill} />
        <div className="flex-1">
          <p className="text-sm font-medium">{t.skills[item.skill]}</p>
          <p className="text-xs text-muted-foreground">{f(t.assessment.question, { n: questionNumber })}</p>
        </div>
      </div>
      <Progress value={sectionPct} aria-label={t.skills[item.skill]} />

      <div key={item.id} className="space-y-6 motion-safe:animate-[fadeIn_200ms_ease-out]">
        {item.has_audio && <AudioPlayer attemptId={attemptId} itemId={item.id} />}
        {item.stimulus && (
          <blockquote lang={lang.code} dir={lang.direction} className="rounded-xl border bg-card p-5 text-base leading-relaxed md:text-lg">
            {item.stimulus}
          </blockquote>
        )}
        <Prompt text={item.prompt} lang={lang.code} dir={lang.direction} />
        <div role="radiogroup" aria-label={item.prompt} className="grid gap-2.5">
          {item.options.map((o) => (
            <Choice key={o.id} selected={selected === o.id} onSelect={() => setSelected(o.id)} title={o.label} lang={lang.code} dir={lang.direction} />
          ))}
        </div>
      </div>

      <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex gap-2">
          <Button variant="ghost" size="sm" onClick={skipSection} disabled={busy}>{t.assessment.skipSection}</Button>
          <Dialog>
            <DialogTrigger asChild>
              <Button variant="ghost" size="sm" disabled={busy}>{t.assessment.finishNow}</Button>
            </DialogTrigger>
            <DialogContent>
              <DialogTitle>{t.assessment.finishNow}</DialogTitle>
              <DialogDescription>{t.assessment.finishConfirm}</DialogDescription>
              <div className="mt-6 flex justify-end gap-2">
                <DialogClose asChild><Button variant="outline">{t.common.cancel}</Button></DialogClose>
                <Button onClick={finishNow} loading={busy}>{t.assessment.finishNow}</Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>
        <Button size="lg" onClick={submit} disabled={selected === null} loading={busy}>{t.assessment.submit}</Button>
      </div>
      <p className="text-xs text-muted-foreground">{t.assessment.noAnswers}</p>
    </div>
  );
}
