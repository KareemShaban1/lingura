"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { CheckCircle2, Info, Pause, Play } from "lucide-react";
import { toast } from "sonner";
import { SkillIcon } from "@/components/skill";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { useInvalidateProfile, useLearningSession } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import { planItemHref } from "./plan-item-card";

/** Counts focused time; pauses automatically when the tab is hidden. The server caps credit at real elapsed time. */
function useFocusTimer(initialRunning: boolean) {
  const [seconds, setSeconds] = useState(0);
  const [running, setRunning] = useState(initialRunning);
  const last = useRef<number>(0);

  useEffect(() => {
    if (!running) return;
    last.current = Date.now();
    const tick = () => {
      if (document.visibilityState === "visible") setSeconds((s) => s + Math.round((Date.now() - last.current) / 1000));
      last.current = Date.now();
    };
    const id = window.setInterval(tick, 1000);
    const onVisibility = () => { last.current = Date.now(); };
    document.addEventListener("visibilitychange", onVisibility);
    return () => {
      window.clearInterval(id);
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, [running]);

  return { seconds, running, toggle: () => setRunning((r) => !r) };
}

const fmt = (s: number) => `${String(Math.floor(s / 60)).padStart(2, "0")}:${String(s % 60).padStart(2, "0")}`;

export function SessionView() {
  const { sessionId } = useParams<{ sessionId: string }>();
  const { t } = useI18n();
  const session = useLearningSession(sessionId);
  const invalidate = useInvalidateProfile();
  const done = !!session.data?.ended_at;
  const timer = useFocusTimer(true);
  const [rating, setRating] = useState<number | null>(null);
  const [saving, setSaving] = useState(false);

  async function complete() {
    if (!session.data) return;
    setSaving(true);
    try {
      await api(`/learning-sessions/${sessionId}/complete`, { method: "POST", body: { active_seconds: timer.seconds, self_rating: rating } });
      invalidate(session.data.learner_profile_id);
      await session.refetch();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t.common.errorTitle);
    } finally {
      setSaving(false);
    }
  }

  const router = useRouter();
  const target = session.data?.plan_item && !session.data.ended_at ? planItemHref(session.data.plan_item, session.data.id) : null;
  useEffect(() => {
    if (target && !target.startsWith("/learning/session/")) router.replace(target);
  }, [target, router]);

  if (session.error) return <ErrorState error={session.error} onRetry={() => session.refetch()} />;
  if (!session.data) return <Skeleton className="h-96" />;
  const item = session.data.plan_item;

  if (done) {
    return (
      <div className="mx-auto max-w-lg py-10 text-center">
        <CheckCircle2 className="mx-auto size-12 text-success" aria-hidden />
        <h1 className="mt-4 font-display text-3xl">{t.session.completed}</h1>
        <p className="mt-2 text-muted-foreground">{Math.round(session.data.active_seconds / 60)} {t.common.minutesLong}</p>
        <Button asChild className="mt-8"><Link href="/learning">{t.session.backToPlan}</Link></Button>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div className="flex items-center gap-3">
        {item?.skill && <SkillIcon skill={item.skill} />}
        <div>
          <p className="text-sm text-muted-foreground">{session.data.target_language.name}</p>
          <h1 className="font-display text-2xl md:text-3xl">{item?.title}</h1>
        </div>
      </div>

      <Card>
        <CardContent className="space-y-3 p-6">
          <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">{t.session.objective}</p>
          <p className="text-lg leading-relaxed">{item?.objective}</p>
          <p className="text-sm text-muted-foreground">{item?.reason}</p>
        </CardContent>
      </Card>

      <div className="flex items-start gap-2 rounded-lg bg-muted p-3 text-sm text-muted-foreground">
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden /> {t.session.selfGuided}
      </div>

      <Card>
        <CardContent className="flex items-center justify-between p-6">
          <div>
            <p className="text-xs text-muted-foreground">{t.session.timer}</p>
            <p className="font-mono text-4xl tabular-nums" aria-live="off">{fmt(timer.seconds)}</p>
          </div>
          <Button variant="outline" onClick={timer.toggle}>
            {timer.running ? <><Pause /> {t.session.pauseTimer}</> : <><Play /> {t.session.resumeTimer}</>}
          </Button>
        </CardContent>
      </Card>

      <fieldset>
        <legend className="mb-3 text-sm font-medium">{t.session.rate}</legend>
        <div role="radiogroup" className="grid grid-cols-5 gap-2">
          {t.session.ratings.map((label, i) => (
            <button
              key={label}
              type="button"
              role="radio"
              aria-checked={rating === i + 1}
              onClick={() => setRating(i + 1)}
              className={cn("rounded-lg border px-1 py-3 text-xs transition-colors", rating === i + 1 ? "border-primary bg-secondary text-secondary-foreground" : "bg-card hover:bg-muted")}
            >
              {label}
            </button>
          ))}
        </div>
      </fieldset>

      <Button size="lg" className="w-full" onClick={complete} loading={saving} disabled={timer.seconds < 1}>{t.session.complete}</Button>
    </div>
  );
}
