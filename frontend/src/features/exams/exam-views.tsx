"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, CheckCircle2, Clock, GraduationCap, Info, XCircle } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { AudioPlayer } from "@/features/practice/audio-player";
import { useActiveProfile } from "@/features/queries";
import { Recorder } from "@/features/speech/recorder";
import { api } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import { countUnits } from "@/lib/text";
import type { ExamAttempt, ExamEstimate, ExamFramework, ExamReviewItem, ExamSectionState } from "@/lib/types";
import { cn } from "@/lib/utils";

const pct = (p?: number) => Math.round((p ?? 0) * 100);

function useEstimateText() {
  const { t, f } = useI18n();
  return (e: ExamEstimate | null | undefined, scale: string, overall = false) => {
    if (!e) return null;
    if (scale === "band") return f(overall ? t.exams.overallBand : t.exams.sectionBand, { band: e.band ?? 0, low: e.low ?? 0, high: e.high ?? 0 });
    return `${t.exams.readiness[e.label ?? "not_yet"]} · ${f(overall ? t.exams.chance : t.exams.sectionChance, { pct: pct(e.probability) })}`;
  };
}

/** Catalog of exam practice for the active learning language. */
export function ExamsView() {
  const { t, f } = useI18n();
  const profile = useActiveProfile();
  const router = useRouter();
  const estimate = useEstimateText();
  const [busy, setBusy] = useState<string | null>(null);
  const exams = useQuery({
    queryKey: ["exams", profile?.id],
    queryFn: () => api<{ data: ExamFramework[] }>(`/learner-profiles/${profile!.id}/exams`).then((r) => r.data),
    enabled: !!profile,
  });

  async function start(slug: string) {
    setBusy(slug);
    try {
      const { data } = await api<{ data: ExamAttempt }>(`/learner-profiles/${profile!.id}/exams/${slug}/attempts`, { method: "POST" });
      router.push(`/exams/${data.id}`);
    } catch (e) {
      toastError(e, t.common.errorTitle);
      setBusy(null);
    }
  }

  if (exams.error) return <ErrorState error={exams.error} onRetry={() => exams.refetch()} />;
  return (
    <div className="space-y-6">
      <Link href="/practice" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4 rtl:rotate-180" /> {t.practice.title}</Link>
      <div>
        <h1 className="font-display text-3xl md:text-4xl">{t.exams.title}</h1>
        <p className="mt-1 text-muted-foreground">{t.exams.subtitle}</p>
      </div>
      {!exams.data ? <Skeleton className="h-64" /> : exams.data.length === 0 ? <EmptyState title={t.exams.none} /> : exams.data.map((x) => {
        const open = x.attempts?.find((a) => a.status === "in_progress");
        return (
          <Card key={x.slug}>
            <CardHeader>
              <CardTitle className="flex items-center gap-2"><GraduationCap className="size-5 text-primary" /> {x.title}</CardTitle>
              <CardDescription>{x.description}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex flex-wrap gap-2">
                {x.sections.map((s) => <Badge key={s.skill} variant="outline">{t.skills[s.skill]} · {f(t.exams.minutes, { n: s.minutes })}</Badge>)}
              </div>
              <p className="flex items-start gap-2 text-xs text-muted-foreground"><Info className="mt-0.5 size-3.5 shrink-0" /> {x.disclaimer}</p>
              <Button onClick={() => (open ? router.push(`/exams/${open.id}`) : start(x.slug))} loading={busy === x.slug}>
                {open ? t.exams.resume : t.exams.start}
              </Button>
              {!!x.attempts?.filter((a) => a.status === "completed").length && (
                <div>
                  <h3 className="mb-2 text-sm font-semibold">{t.exams.history}</h3>
                  <ul className="divide-y rounded-lg border text-sm">
                    {x.attempts.filter((a) => a.status === "completed").map((a) => (
                      <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <span className="text-muted-foreground">{new Date(a.completed_at ?? a.started_at).toLocaleDateString()}</span>
                        <span>{estimate(a.overall, x.scale, true) ?? t.exams.noOverall}</span>
                        <Link className="text-primary hover:underline" href={`/exams/${a.id}/results`}>{t.exams.results}</Link>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}

function useAttempt(id: string) {
  return useQuery({ queryKey: ["exam-attempt", id], queryFn: () => api<{ data: ExamAttempt }>(`/exam-attempts/${id}`).then((r) => r.data) });
}

/** Counts down to the server deadline (corrected for clock skew) and fires once at zero. */
function Countdown({ deadline, serverTime, onExpire }: { deadline: string; serverTime: string; onExpire: () => void }) {
  const { t } = useI18n();
  const [left, setLeft] = useState<number | null>(null);
  const fired = useRef(false);
  const skew = useRef<number | null>(null);
  useEffect(() => {
    skew.current ??= Date.parse(serverTime) - Date.now();
    const tick = () => {
      const remaining = Math.max(0, Math.round((Date.parse(deadline) - (Date.now() + (skew.current ?? 0))) / 1000));
      setLeft(remaining);
      if (remaining === 0 && !fired.current) {
        fired.current = true;
        onExpire();
      }
    };
    tick();
    const id = window.setInterval(tick, 1000);
    return () => window.clearInterval(id);
  }, [deadline, serverTime, onExpire]);
  if (left === null) return null;
  const mm = String(Math.floor(left / 60)).padStart(2, "0");
  const ss = String(left % 60).padStart(2, "0");
  return (
    <span role="timer" aria-label={t.exams.timeLeft} className={cn("inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 font-mono text-lg tabular-nums", left < 60 && "border-destructive text-destructive")}>
      <Clock className="size-4" aria-hidden /> {mm}:{ss}
    </span>
  );
}

function SectionList({ attempt, onStart, onSkip, busy }: { attempt: ExamAttempt; onStart: (s: ExamSectionState) => void; onSkip: (s: ExamSectionState) => void; busy: boolean }) {
  const { t, f } = useI18n();
  return (
    <ol className="space-y-2">
      {attempt.sections.map((s, i) => (
        <li key={s.skill}>
          <Card>
            <CardContent className="flex flex-wrap items-center gap-3 p-4">
              <span className="grid size-8 place-items-center rounded-full bg-secondary text-sm font-semibold">{i + 1}</span>
              <div className="min-w-0 flex-1">
                <p className="font-medium">{t.skills[s.skill]} <span className="text-sm text-muted-foreground">· {f(t.exams.minutes, { n: s.minutes })}</span></p>
                <p className="text-xs text-muted-foreground">{t.exams.statuses[s.status]}{s.reason ? ` — ${t.exams.reasons[s.reason] ?? s.reason}` : ""}</p>
              </div>
              {s.status === "pending" && (
                <div className="flex gap-2">
                  <Button size="sm" variant="ghost" onClick={() => onSkip(s)} disabled={busy}>{t.exams.skipSection}</Button>
                  <Button size="sm" onClick={() => onStart(s)} disabled={busy}>{t.exams.startSection}</Button>
                </div>
              )}
              {s.status === "completed" && <CheckCircle2 className="size-5 text-success" aria-hidden />}
            </CardContent>
          </Card>
        </li>
      ))}
    </ol>
  );
}

export function ExamPlayer() {
  const { t, f } = useI18n();
  const { attemptId } = useParams<{ attemptId: string }>();
  const router = useRouter();
  const qc = useQueryClient();
  const profile = useActiveProfile();
  const attempt = useAttempt(attemptId);
  const [busy, setBusy] = useState(false);
  const [chosen, setChosen] = useState<Record<string, number>>({});
  const [text, setText] = useState("");
  const recordingId = useRef<string | null>(null);
  const finished = attempt.data?.status === "completed";

  useEffect(() => {
    if (finished) router.replace(`/exams/${attemptId}/results`);
  }, [finished, attemptId, router]);

  const a = attempt.data;
  const current = a?.current;
  const section = a?.sections.find((s) => s.status === "in_progress");

  async function call(path: string, body?: unknown) {
    setBusy(true);
    try {
      const { data } = await api<{ data: ExamAttempt }>(`/exam-attempts/${attemptId}${path}`, { method: "POST", body });
      qc.setQueryData(["exam-attempt", attemptId], data);
      if (path.endsWith("/submit")) {
        setText("");
        recordingId.current = null;
      }
    } catch (e) {
      toastError(e, t.common.errorTitle);
      attempt.refetch();
    } finally {
      setBusy(false);
    }
  }

  // Keep the latest submit in a ref so the countdown can fire it without re-subscribing every keystroke.
  const submitRef = useRef<() => void>(() => undefined);
  useEffect(() => {
    submitRef.current = () => {
      if (!section) return;
      const body = section.skill === "writing" ? { text } : section.skill === "speaking" ? (recordingId.current ? { recording_id: recordingId.current } : undefined) : undefined;
      if (section.skill === "speaking" && !body) {
        attempt.refetch(); // nothing recorded: the server closes the section
        return;
      }
      call(`/sections/${section.skill}/submit`, body);
    };
  });
  const [expired, setExpired] = useState(false);
  const onExpire = useCallback(() => {
    setExpired(true);
    submitRef.current();
  }, []);

  if (attempt.error) return <ErrorState error={attempt.error} onRetry={() => attempt.refetch()} />;
  if (!a || finished) return <Skeleton className="h-96" />;

  async function answer(itemId: string, optionId: number) {
    setChosen((c) => ({ ...c, [itemId]: optionId }));
    try {
      await api(`/exam-attempts/${attemptId}/answers`, { method: "POST", body: { item_id: itemId, option_id: optionId } });
    } catch (e) {
      toastError(e, t.common.errorTitle);
      attempt.refetch();
    }
  }

  const items = current?.groups?.flatMap((g) => g.items) ?? [];
  const answeredCount = items.filter((i) => (chosen[i.id] ?? i.chosen_option_id) != null).length;

  return (
    <div className="space-y-6" lang={undefined}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl md:text-3xl">{a.framework.title}</h1>
          <p className="mt-1 max-w-xl text-xs text-muted-foreground">{a.framework.disclaimer}</p>
        </div>
        {section?.deadline_at && <Countdown key={section.skill} deadline={section.deadline_at} serverTime={a.server_time} onExpire={onExpire} />}
      </div>

      {!section && <SectionList attempt={a} busy={busy} onStart={(s) => { setExpired(false); call(`/sections/${s.skill}/start`); }} onSkip={(s) => call(`/sections/${s.skill}/skip`)} />}

      {section && current && (
        <section className="space-y-5" aria-label={t.skills[section.skill]}>
          <h2 className="text-lg font-semibold">{t.skills[section.skill]}</h2>
          {expired && <p role="status" className="rounded-lg bg-warning/15 p-3 text-sm">{t.exams.timeUp}</p>}

          {current.groups?.map((g) => (
            <Card key={g.key}>
              <CardContent className="space-y-4 p-5">
                {section.skill === "listening" ? (
                  <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">{t.exams.listenNote}</p>
                    {g.has_audio && <AudioPlayer src={`/exam-attempts/${attemptId}/items/${g.audio_item_id}/audio`} />}
                  </div>
                ) : (
                  <p lang={a.language.code} dir={a.language.direction} className="whitespace-pre-line rounded-lg bg-muted/50 p-4 leading-relaxed">{g.stimulus}</p>
                )}
                {g.items.map((item, n) => (
                  <fieldset key={item.id} className="space-y-2">
                    <legend lang={a.language.code} dir={a.language.direction} className="font-medium">{n + 1}. {item.prompt}</legend>
                    <div className="grid gap-2 sm:grid-cols-2">
                      {item.options.map((o) => {
                        const selected = (chosen[item.id] ?? item.chosen_option_id) === o.id;
                        return (
                          <label key={o.id} lang={a.language.code} dir={a.language.direction}
                            className={cn("flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm hover:border-primary/50", selected && "border-primary bg-secondary/60")}>
                            <input type="radio" name={item.id} className="accent-primary" checked={selected} onChange={() => answer(item.id, o.id)} disabled={busy || expired} />
                            {o.label}
                          </label>
                        );
                      })}
                    </div>
                  </fieldset>
                ))}
              </CardContent>
            </Card>
          ))}

          {current.task && (
            <Card>
              <CardContent className="space-y-4 p-5">
                <p lang={a.language.code} dir={a.language.direction} className="rounded-lg bg-secondary/40 p-4 text-lg leading-relaxed">{current.task.prompt}</p>
                {section.skill === "writing" ? (
                  <>
                    <textarea value={text} onChange={(e) => setText(e.target.value)} rows={12} lang={a.language.code} dir={a.language.direction} aria-label={t.skills.writing}
                      disabled={busy || expired}
                      className="w-full rounded-xl border border-input bg-card p-4 text-base focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40" />
                    <p className="text-sm text-muted-foreground">
                      {f(current.task.count_unit === "characters" ? t.writing.characters : t.writing.words, { n: countUnits(text) })} · {f(current.task.count_unit === "characters" ? t.writing.targetChars : t.writing.target, { min: current.task.min_words, max: current.task.max_words })}
                    </p>
                  </>
                ) : profile && (
                  <Recorder profileId={profile.id} purpose="speaking_assessment" disabled={busy || expired} onRecorded={(id) => { recordingId.current = id; call(`/sections/speaking/submit`, { recording_id: id }); }} />
                )}
              </CardContent>
            </Card>
          )}

          <div className="flex flex-wrap items-center justify-between gap-3">
            {items.length > 0 && <span className="text-sm text-muted-foreground">{f(t.exams.answered, { n: answeredCount, total: items.length })}</span>}
            {section.skill !== "speaking" && (
              <Button size="lg" loading={busy} disabled={expired || (section.skill === "writing" && countUnits(text) < 3)}
                onClick={() => { if (window.confirm(t.exams.submitConfirm)) submitRef.current(); }}>
                {t.exams.submitSection}
              </Button>
            )}
          </div>
        </section>
      )}
    </div>
  );
}

export function ExamResults() {
  const { t, f } = useI18n();
  const { attemptId } = useParams<{ attemptId: string }>();
  const attempt = useAttempt(attemptId);
  const estimate = useEstimateText();
  const done = attempt.data?.status === "completed";
  const review = useQuery({
    queryKey: ["exam-review", attemptId],
    queryFn: () => api<{ data: ExamReviewItem[] }>(`/exam-attempts/${attemptId}/review`).then((r) => r.data),
    enabled: done,
  });

  if (attempt.error) return <ErrorState error={attempt.error} onRetry={() => attempt.refetch()} />;
  if (!attempt.data) return <Skeleton className="h-96" />;
  const a = attempt.data;
  if (!done) return <EmptyState title={t.exams.resume} action={<Button asChild><Link href={`/exams/${a.id}`}>{t.exams.resume}</Link></Button>} />;
  const overall = estimate(a.result?.overall, a.framework.scale, true);

  return (
    <div className="space-y-6">
      <Link href="/practice/exams" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4 rtl:rotate-180" /> {t.exams.backToExams}</Link>
      <div>
        <p className="text-sm text-muted-foreground">{t.exams.finished}</p>
        <h1 className="font-display text-3xl">{a.framework.title}</h1>
      </div>
      <Card className="border-primary/40">
        <CardContent className="space-y-2 p-5">
          <p className="font-display text-2xl">{overall ?? t.exams.noOverall}</p>
          <p className="text-xs text-muted-foreground">{a.framework.disclaimer}</p>
        </CardContent>
      </Card>

      <div className="grid gap-3 sm:grid-cols-2">
        {a.sections.map((s) => (
          <Card key={s.skill}>
            <CardContent className="space-y-1 p-4">
              <p className="font-medium">{t.skills[s.skill]}</p>
              {s.estimate ? <p className="text-sm">{estimate(s.estimate, a.framework.scale)}</p> : <p className="text-sm text-muted-foreground">{t.exams.statuses[s.status]}{s.reason ? ` — ${t.exams.reasons[s.reason] ?? s.reason}` : ""}</p>}
              <p className="text-xs text-muted-foreground">
                {s.raw && f(t.exams.raw, s.raw)}{s.raw && s.level ? " · " : ""}{s.level && f(t.exams.level, { level: s.level })}
              </p>
              {s.writing_submission_id && <Link href={`/practice/writing/submissions/${s.writing_submission_id}`} className="text-sm text-primary hover:underline">{t.exams.seeFeedback}</Link>}
            </CardContent>
          </Card>
        ))}
      </div>
      <p className="flex items-start gap-2 text-sm text-muted-foreground"><Info className="mt-0.5 size-4 shrink-0" /> {t.exams.howEstimated}</p>

      <section className="space-y-3">
        <h2 className="text-lg font-semibold">{t.exams.review}</h2>
        {review.error ? <ErrorState error={review.error} /> : !review.data ? <Skeleton className="h-64" /> : (
          Object.entries(Object.groupBy(review.data, (i) => `${i.skill}:${i.group}`)).map(([key, group]) => (
            <Card key={key}>
              <CardContent className="space-y-3 p-5">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{t.skills[group![0].skill]}{group![0].skill === "listening" ? ` · ${t.exams.script}` : ""}</p>
                <p lang={a.language.code} dir={a.language.direction} className="whitespace-pre-line rounded-lg bg-muted/50 p-3 text-sm">{group![0].stimulus}</p>
                {group!.map((i) => {
                  const right = i.options.find((o) => o.is_correct);
                  const ok = i.chosen_option_id === right?.id;
                  return (
                    <div key={i.id} className="space-y-1 text-sm">
                      <p lang={a.language.code} dir={a.language.direction} className="flex items-start gap-2 font-medium">
                        {ok ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-label="✓" /> : <XCircle className="mt-0.5 size-4 shrink-0 text-destructive" aria-label="✗" />} {i.prompt}
                      </p>
                      {!ok && <p className="ps-6 text-muted-foreground">{t.exams.yourAnswer}: <span lang={a.language.code}>{i.options.find((o) => o.id === i.chosen_option_id)?.label ?? "—"}</span></p>}
                      <p className="ps-6">{t.exams.correctAnswer}: <span lang={a.language.code} className="font-medium">{right?.label}</span></p>
                      {i.explanation && <p className="ps-6 text-muted-foreground">{i.explanation}</p>}
                    </div>
                  );
                })}
              </CardContent>
            </Card>
          ))
        )}
      </section>
    </div>
  );
}
