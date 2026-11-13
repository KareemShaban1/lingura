"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight, Info, Loader2, PenLine } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { useActivitySession } from "@/features/practice/use-activity-session";
import { useActiveProfile, useInvalidateProfile, useWritingSubmission, useWritingTask, useWritingTasks } from "@/features/queries";
import { ScoreBar } from "@/features/tutor/report-view";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import type { WritingSubmission } from "@/lib/types";
import { toastError } from "@/lib/errors";

const wordCount = (text: string) => (text.trim().match(/[\p{L}\p{M}\p{N}']+/gu) ?? []).length;

export function WritingListView() {
  const { t, f } = useI18n();
  const profile = useActiveProfile();
  const tasks = useWritingTasks(profile?.id);
  if (tasks.error) return <ErrorState error={tasks.error} onRetry={() => tasks.refetch()} />;
  return (
    <div className="space-y-6">
      <Link href="/practice" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4 rtl:rotate-180" /> {t.practice.title}</Link>
      <div>
        <h1 className="font-display text-3xl md:text-4xl">{t.writing.title}</h1>
        <p className="mt-1 text-muted-foreground">{t.writing.subtitle}</p>
      </div>
      {!tasks.data ? <Skeleton className="h-64" /> : tasks.data.length === 0 ? <EmptyState title={t.practice.empty} /> : (
        <div className="grid gap-3 sm:grid-cols-2">
          {tasks.data.map((task) => (
            <Link key={task.id} href={`/practice/writing/${task.id}`} className="group">
              <Card className="h-full group-hover:border-primary/50">
                <CardContent className="flex h-full gap-3 p-4">
                  <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-muted text-skill-writing"><PenLine className="size-[18px]" /></span>
                  <div className="min-w-0 flex-1">
                    <p className="font-medium">{task.title}</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">{f(t.writing.target, { min: task.min_words, max: task.max_words })}</p>
                    <div className="mt-2 flex gap-2">
                      <Badge variant="outline" className="font-mono">{task.level.label}</Badge>
                      {task.last_score != null && <Badge variant="success">{f(t.writing.last, { score: task.last_score })}</Badge>}
                    </div>
                  </div>
                  <ArrowRight className="size-4 text-muted-foreground rtl:rotate-180" />
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}

/** The learner's text with each verified correction span highlighted (offsets are validated server-side). */
function AnnotatedText({ text, corrections, lang, dir }: { text: string; corrections: NonNullable<WritingSubmission["evaluation"]>["corrections"]; lang: string; dir: "ltr" | "rtl" }) {
  const parts = useMemo(() => {
    const chars = Array.from(text);
    const out: { text: string; mark?: number }[] = [];
    let cursor = 0;
    corrections.forEach((c, i) => {
      if (c.start < cursor) return;
      out.push({ text: chars.slice(cursor, c.start).join("") });
      out.push({ text: chars.slice(c.start, c.start + c.length).join(""), mark: i });
      cursor = c.start + c.length;
    });
    out.push({ text: chars.slice(cursor).join("") });
    return out;
  }, [text, corrections]);
  return (
    <p lang={lang} dir={dir} className="whitespace-pre-wrap leading-relaxed">
      {parts.map((p, i) => p.mark === undefined ? <span key={i}>{p.text}</span> : (
        <mark key={i} className="rounded bg-warning/30 px-0.5 text-foreground underline decoration-warning decoration-wavy underline-offset-4" title={corrections[p.mark].correction}>
          {p.text}<sup className="ms-0.5 font-mono text-[10px]">{p.mark + 1}</sup>
        </mark>
      ))}
    </p>
  );
}

export function SubmissionResult({ submission }: { submission: WritingSubmission }) {
  const { t, f } = useI18n();
  const e = submission.evaluation;
  const lang = submission.language;
  if (submission.status === "pending") return <div role="status" className="flex items-center gap-3 rounded-xl border p-6 text-muted-foreground"><Loader2 className="size-5 animate-spin" /> {t.writing.evaluating}</div>;
  if (submission.status === "failed" || !e) return <ErrorState error={t.writing.failed} />;
  return (
    <div className="space-y-5">
      <div className="grid gap-4 md:grid-cols-[14rem_1fr]">
        <Card><CardContent className="p-5">
          <p className="text-xs text-muted-foreground">{t.writing.score}</p>
          <p className="font-display text-5xl">{e.overall_score.toFixed(1)}<span className="text-xl text-muted-foreground">/5</span></p>
          <p className="mt-2 text-sm">{f(t.writing.estimated, { level: e.estimated_level.label })}</p>
        </CardContent></Card>
        <Card><CardContent className="space-y-3 p-5">
          {e.criteria.map((c) => (
            <div key={c.criterion}>
              <ScoreBar label={t.writing.criteria[c.criterion as keyof typeof t.writing.criteria] ?? c.criterion} score={c.score} />
              <p dir="auto" className="mt-1 text-xs text-muted-foreground">{c.comment}</p>
            </div>
          ))}
        </CardContent></Card>
      </div>
      <p dir="auto" className="text-sm">{e.summary}</p>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card><CardHeader><CardTitle>{t.writing.original}</CardTitle></CardHeader>
          <CardContent><AnnotatedText text={submission.text} corrections={e.corrections} lang={lang.code} dir={lang.direction} /></CardContent></Card>
        <Card><CardHeader><CardTitle>{t.writing.corrected}</CardTitle></CardHeader>
          <CardContent><p lang={lang.code} dir={lang.direction} className="whitespace-pre-wrap leading-relaxed">{e.corrected_text}</p></CardContent></Card>
      </div>
      <Card><CardHeader><CardTitle>{t.writing.corrections}</CardTitle></CardHeader>
        <CardContent>
          {e.corrections.length === 0 ? <p className="text-sm text-muted-foreground">{t.writing.noCorrections}</p> : (
            <ol className="space-y-3 text-sm">
              {e.corrections.map((c, i) => (
                <li key={i} className="flex gap-3">
                  <span className="font-mono text-xs text-muted-foreground">{i + 1}</span>
                  <div>
                    <p lang={lang.code} dir={lang.direction}><span className="line-through decoration-destructive/70">{c.original}</span> → <strong>{c.correction}</strong> <Badge variant="muted">{c.type}</Badge></p>
                    <p dir="auto" className="text-muted-foreground">{c.explanation}</p>
                  </div>
                </li>
              ))}
            </ol>
          )}
          <p className="mt-4 text-xs text-muted-foreground">{f(t.writing.stats, { sentences: e.stats.sentences, avg: e.stats.avg_sentence_length, variety: Math.round(e.stats.lexical_variety * 100) })}</p>
        </CardContent>
      </Card>
      <p className="flex items-start gap-2 rounded-lg bg-muted p-3 text-sm text-muted-foreground"><Info className="mt-0.5 size-4 shrink-0" /> {t.writing.aiNote}</p>
    </div>
  );
}

export function WritingTaskView() {
  const { taskId } = useParams<{ taskId: string }>();
  const planSession = useSearchParams().get("session");
  const { t, f } = useI18n();
  const profile = useActiveProfile();
  const qc = useQueryClient();
  const invalidate = useInvalidateProfile();
  const task = useWritingTask(profile?.id, taskId);
  const session = useActivitySession(profile?.id, "exercise", planSession);
  const [text, setText] = useState("");
  const [submissionId, setSubmissionId] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const submission = useWritingSubmission(submissionId);

  if (task.error) return <ErrorState error={task.error} onRetry={() => task.refetch()} />;
  if (!task.data || !profile) return <Skeleton className="h-96" />;
  const d = task.data;
  const words = wordCount(text);

  async function submit() {
    setSubmitting(true);
    try {
      const { data } = await api<{ data: WritingSubmission }>(`/learner-profiles/${profile!.id}/writing-tasks/${taskId}/submissions`, { method: "POST", body: { text } });
      setSubmissionId(data.id);
      await session.complete();
      invalidate(profile!.id);
      qc.invalidateQueries({ queryKey: ["profile", profile!.id, "writing-task", taskId] });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <Link href="/practice/writing" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4 rtl:rotate-180" /> {t.writing.title}</Link>
      <div>
        <div className="flex items-center gap-2 text-xs text-muted-foreground"><Badge variant="outline" className="font-mono">{d.level.label}</Badge>{f(t.writing.target, { min: d.min_words, max: d.max_words })}</div>
        <h1 className="mt-2 font-display text-3xl">{d.title}</h1>
        <p lang={d.language.code} dir={d.language.direction} className="mt-3 rounded-xl border bg-secondary/30 p-4 text-lg leading-relaxed">{d.prompt}</p>
      </div>

      {submission.data ? (
        <>
          <SubmissionResult submission={submission.data} />
          {submission.data.status !== "pending" && <Button variant="outline" onClick={() => { setSubmissionId(null); setText(""); }}>{t.writing.writeAgain}</Button>}
        </>
      ) : (
        <div className="space-y-3">
          <textarea value={text} onChange={(e) => setText(e.target.value)} rows={10} lang={d.language.code} dir={d.language.direction}
            aria-label={t.writing.write} spellCheck={false}
            className="w-full rounded-xl border border-input bg-card p-4 text-lg leading-relaxed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40" />
          <div className="flex flex-wrap items-center justify-between gap-3">
            <span className={words < d.min_words || words > d.max_words ? "text-sm text-warning" : "text-sm text-success"} aria-live="polite">
              {f(t.writing.words, { n: words })} · {f(t.writing.target, { min: d.min_words, max: d.max_words })}
            </span>
            <Button size="lg" onClick={submit} loading={submitting} disabled={words < 3}>{t.writing.submit}</Button>
          </div>
        </div>
      )}

      {d.submissions.length > 0 && !submissionId && (
        <Card><CardHeader><CardTitle>{t.writing.history}</CardTitle></CardHeader>
          <CardContent><ul className="divide-y text-sm">
            {d.submissions.map((s) => (
              <li key={s.id}><button className="flex w-full justify-between py-2 hover:text-primary" onClick={() => setSubmissionId(s.id)}>
                <span>{new Date(s.created_at).toLocaleString()} · {f(t.writing.words, { n: s.word_count })}</span>
                <span className="font-mono">{s.score != null ? `${s.score}/5` : s.status}</span>
              </button></li>
            ))}
          </ul></CardContent>
        </Card>
      )}
    </div>
  );
}
