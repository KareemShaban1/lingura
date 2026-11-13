"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Check, CloudOff, Download, RefreshCw, Trash2, Wifi, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { EmptyState } from "@/components/ui/states";
import { api } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import {
  checkLocally, deletePack, getPack, listOutbox, listPacks, offlineSupported, queueAttempt, savePack,
  type OfflineAnswer, type OfflineExercise, type OfflinePack, type StoredPack,
} from "@/lib/offline-store";
import { syncOffline } from "@/lib/offline-sync";
import { cn } from "@/lib/utils";

function useOnline() {
  const [online, setOnline] = useState(true);
  useEffect(() => {
    const update = () => setOnline(navigator.onLine);
    update();
    window.addEventListener("online", update);
    window.addEventListener("offline", update);
    return () => {
      window.removeEventListener("online", update);
      window.removeEventListener("offline", update);
    };
  }, []);
  return online;
}

/** "Save for offline" on a lesson page. */
export function SaveOfflineButton({ profileId, lessonId }: { profileId: string; lessonId: string }) {
  const { t } = useI18n();
  const [saved, setSaved] = useState<boolean | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!offlineSupported()) return;
    getPack(lessonId).then((p) => setSaved(!!p)).catch(() => setSaved(false));
  }, [lessonId]);
  if (saved === null) return null;

  async function save() {
    setBusy(true);
    try {
      const { data } = await api<{ data: OfflinePack }>(`/learner-profiles/${profileId}/lessons/${lessonId}/offline-pack`);
      await savePack({ lessonId, profileId, pack: data });
      // Ask the service worker to cache the offline page and its assets for use without a connection.
      navigator.serviceWorker?.controller?.postMessage({ type: "precache-offline" });
      setSaved(true);
      toast.success(t.offline.savedToast);
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    await deletePack(lessonId);
    setSaved(false);
  }

  return saved ? (
    <div className="flex items-center gap-2">
      <Badge variant="success"><Check className="size-3" /> {t.offline.saved}</Badge>
      <Button size="sm" variant="ghost" onClick={remove} aria-label={t.offline.remove}><Trash2 /></Button>
    </div>
  ) : (
    <Button size="sm" variant="outline" onClick={save} loading={busy}><Download /> {t.offline.save}</Button>
  );
}

/** Syncs queued offline attempts whenever the app is online (mounted in the app shell). */
export function OfflineSyncer({ onSynced }: { onSynced?: () => void }) {
  const { t, f } = useI18n();
  useEffect(() => {
    if (!offlineSupported()) return;
    const run = () => syncOffline().then((r) => {
      const n = r.filter((x) => x.status === "synced").length;
      if (n) {
        toast.success(f(t.offline.synced, { n }));
        onSynced?.();
      }
    });
    run();
    window.addEventListener("online", run);
    return () => window.removeEventListener("online", run);
  }, [f, t, onSynced]);
  return null;
}

function ExerciseRunner({ stored, exercise, onDone }: { stored: StoredPack; exercise: OfflineExercise; onDone: () => void }) {
  const { t, f } = useI18n();
  const lang = stored.pack.language;
  const [index, setIndex] = useState(0);
  const [answers, setAnswers] = useState<OfflineAnswer[]>([]);
  const [current, setCurrent] = useState<OfflineAnswer | null>(null);
  const [checked, setChecked] = useState<boolean | null>(null);
  const [order, setOrder] = useState<number[]>([]);
  const q = exercise.questions[index];
  const last = index === exercise.questions.length - 1;

  function check() {
    const a = exercise.type === "ordering" ? { question_id: q.id, order } : current;
    if (!a) return;
    setChecked(checkLocally(exercise, q, a));
    setAnswers((xs) => [...xs, a]);
  }

  async function next() {
    if (!last) {
      setIndex(index + 1);
      setCurrent(null);
      setChecked(null);
      setOrder([]);
      return;
    }
    const score = answers.filter((a, i) => checkLocally(exercise, exercise.questions[i], a)).length / exercise.questions.length;
    await queueAttempt({
      client_ref: crypto.randomUUID(), profileId: stored.profileId, lesson_id: stored.lessonId, exercise_id: exercise.id,
      answers, provisional_score: score, finished_at: new Date().toISOString(),
    });
    toast.success(f(t.offline.provisional, { pct: Math.round(score * 100) }));
    onDone();
  }

  const correctText = exercise.type === "cloze" ? q.answers[0]
    : exercise.type === "ordering" ? [...q.options].sort((a, b) => (a.position ?? 0) - (b.position ?? 0)).map((o) => o.label).join(" ")
    : q.options.find((o) => o.correct)?.label;

  return (
    <Card>
      <CardContent className="space-y-4 p-5">
        <p className="text-xs text-muted-foreground">{index + 1} / {exercise.questions.length}</p>
        {exercise.text && <p lang={lang.code} dir={lang.direction} className="whitespace-pre-line rounded-lg bg-muted/50 p-4">{exercise.text}</p>}
        {q.prompt && <p lang={lang.code} dir={lang.direction} className="text-lg font-medium">{q.prompt}</p>}
        {exercise.type === "cloze" ? (
          <Input lang={lang.code} dir={lang.direction} aria-label={t.offline.typeAnswer} placeholder={t.offline.typeAnswer} disabled={checked !== null}
            value={current?.text ?? ""} onChange={(e) => setCurrent({ question_id: q.id, text: e.target.value })} />
        ) : exercise.type === "ordering" ? (
          <div className="space-y-2">
            <p lang={lang.code} dir={lang.direction} className="min-h-10 rounded-lg border border-dashed p-2">{order.map((id) => q.options.find((o) => o.id === id)?.label).join(" ")}</p>
            <div className="flex flex-wrap gap-2">
              {q.options.filter((o) => !order.includes(o.id)).map((o) => (
                <Button key={o.id} size="sm" variant="outline" lang={lang.code} disabled={checked !== null} onClick={() => setOrder([...order, o.id])}>{o.label}</Button>
              ))}
              {order.length > 0 && checked === null && <Button size="sm" variant="ghost" onClick={() => setOrder([])} aria-label="reset">↺</Button>}
            </div>
          </div>
        ) : (
          <div className="grid gap-2 sm:grid-cols-2">
            {q.options.map((o) => (
              <button key={o.id} type="button" lang={lang.code} dir={lang.direction} disabled={checked !== null}
                onClick={() => setCurrent({ question_id: q.id, option_id: o.id })}
                className={cn("rounded-lg border px-3 py-2 text-start", current?.option_id === o.id && "border-primary bg-secondary/60",
                  checked !== null && o.correct && "border-success bg-success/10")}>
                {o.label}
              </button>
            ))}
          </div>
        )}
        {checked !== null && (
          <p role="status" className={cn("flex flex-wrap items-center gap-2 text-sm font-medium", checked ? "text-success" : "text-destructive")}>
            {checked ? <Check className="size-4" /> : <X className="size-4" />} {checked ? t.offline.correct : t.offline.incorrect}
            {!checked && correctText && <span className="font-normal text-foreground" lang={lang.code}>· {f(t.offline.answer, { a: correctText })}</span>}
          </p>
        )}
        <div className="flex justify-end">
          {checked === null
            ? <Button onClick={check} disabled={exercise.type === "ordering" ? order.length !== q.options.length : !current}>{t.offline.check}</Button>
            : <Button onClick={next}>{last ? t.offline.finish : t.offline.next}</Button>}
        </div>
      </CardContent>
    </Card>
  );
}

function PackView({ stored, done, onBack, onDone }: { stored: StoredPack; done: Set<string>; onBack: () => void; onDone: () => void }) {
  const { t, f } = useI18n();
  const [running, setRunning] = useState<OfflineExercise | null>(null);
  const [now] = useState(() => Date.now());
  const p = stored.pack;
  const expired = Date.parse(p.expires_at) < now;
  if (running) return <ExerciseRunner stored={stored} exercise={running} onDone={() => { setRunning(null); onDone(); }} />;
  return (
    <div className="space-y-5">
      <button onClick={onBack} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4 rtl:rotate-180" /> {t.offline.back}</button>
      <h2 className="font-display text-2xl">{p.lesson.title}</h2>
      {expired && <p className="rounded-lg bg-warning/15 p-3 text-sm">{t.offline.expired}</p>}
      {p.lesson.intro && <p className="text-muted-foreground">{p.lesson.intro}</p>}
      {p.lesson.grammar && (
        <Card>
          <CardHeader><CardTitle className="text-base">{t.offline.grammar}: {p.lesson.grammar.title}</CardTitle></CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p>{p.lesson.grammar.explanation}</p>
            {p.lesson.grammar.examples.map((x, i) => <p key={i}><span lang={p.language.code} dir={p.language.direction} className="font-medium">{x.sentence}</span> — {x.translation}</p>)}
          </CardContent>
        </Card>
      )}
      <Card>
        <CardHeader><CardTitle className="text-base">{t.offline.words}</CardTitle></CardHeader>
        <CardContent>
          <ul className="grid gap-2 sm:grid-cols-2">
            {p.lesson.words.map((w) => (
              <li key={w.lemma} className="text-sm"><span lang={p.language.code} className="font-medium">{w.lemma}</span>{w.pronunciation && <span className="ms-1 text-muted-foreground">{w.pronunciation}</span>} — {w.meaning}</li>
            ))}
          </ul>
        </CardContent>
      </Card>
      <section className="space-y-2">
        <h3 className="font-semibold">{t.offline.exercises}</h3>
        {p.exercises.map((e) => (
          <Card key={e.id}>
            <CardContent className="flex items-center justify-between gap-3 p-4">
              <span className="text-sm font-medium">{e.title ?? t.practice.types[e.type]}</span>
              {done.has(e.id) ? <Badge variant="success">{t.offline.doneLocally}</Badge> : <Button size="sm" onClick={() => setRunning(e)}>{t.offline.open}</Button>}
            </CardContent>
          </Card>
        ))}
        {p.skipped_exercises > 0 && <p className="text-xs text-muted-foreground">{f(t.offline.skipped, { n: p.skipped_exercises })}</p>}
      </section>
    </div>
  );
}

/** Standalone page: works without the API (reads IndexedDB only); syncs when the connection returns. */
export function OfflineView() {
  const { t, f } = useI18n();
  const online = useOnline();
  const [packs, setPacks] = useState<StoredPack[] | null>(null);
  const [pending, setPending] = useState<string[]>([]);
  const [open, setOpen] = useState<string | null>(null);
  const [syncing, setSyncing] = useState(false);

  const reload = useCallback(() => {
    if (!offlineSupported()) {
      setPacks([]);
      return;
    }
    listPacks().then(setPacks).catch(() => setPacks([]));
    listOutbox().then((o) => setPending(o.map((a) => a.exercise_id))).catch(() => setPending([]));
  }, []);
  useEffect(() => {
    const id = window.setTimeout(reload, 0);
    return () => window.clearTimeout(id);
  }, [reload]);
  const done = useMemo(() => new Set(pending), [pending]);

  async function sync() {
    setSyncing(true);
    const r = await syncOffline();
    setSyncing(false);
    const n = r.filter((x) => x.status === "synced").length;
    if (n) toast.success(f(t.offline.synced, { n }));
    reload();
  }

  const current = packs?.find((p) => p.lessonId === open);
  return (
    <main className="mx-auto w-full max-w-3xl space-y-6 px-4 py-8">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-display text-3xl">{t.offline.title}</h1>
          <p className="mt-1 text-sm text-muted-foreground">{t.offline.subtitle}</p>
        </div>
        <Badge variant={online ? "success" : "warning"}>
          {online ? <><Wifi className="size-3" /> {t.offline.online}</> : <><CloudOff className="size-3" /> {t.offline.youreOffline}</>}
        </Badge>
      </div>
      {pending.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-card p-3 text-sm">
          <span>{f(t.offline.pending, { n: pending.length })}</span>
          <Button size="sm" variant="outline" onClick={sync} disabled={!online} loading={syncing}><RefreshCw /> {t.offline.syncNow}</Button>
        </div>
      )}
      {current ? (
        <PackView stored={current} done={done} onBack={() => setOpen(null)} onDone={reload} />
      ) : packs === null ? null : packs.length === 0 ? <EmptyState title={t.offline.none} /> : (
        <ul className="space-y-2">
          {packs.map((p) => (
            <li key={p.lessonId}>
              <Card>
                <CardContent className="flex items-center justify-between gap-3 p-4">
                  <div>
                    <p className="font-medium">{p.pack.lesson.title}</p>
                    <p className="text-xs text-muted-foreground">{p.pack.language.code} · {new Date(p.pack.downloaded_at).toLocaleDateString()}</p>
                  </div>
                  <Button size="sm" onClick={() => setOpen(p.lessonId)}>{t.offline.open}</Button>
                </CardContent>
              </Card>
            </li>
          ))}
        </ul>
      )}
      {online && <Link href="/home" className="inline-block text-sm text-primary hover:underline">{t.offline.goOnline}</Link>}
    </main>
  );
}
