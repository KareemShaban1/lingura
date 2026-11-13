"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Check, ChevronDown, ClipboardCheck, Clock, RotateCcw } from "lucide-react";
import { SkillIcon } from "@/components/skill";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { useInvalidateProfile, useStartAssessment } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import type { LearningSession, PlanItem } from "@/lib/types";
import { cn } from "@/lib/utils";
import { toastError } from "@/lib/errors";

/** Content-bound items open their player; self-guided items open the session runner. */
export function planItemHref(item: PlanItem, sessionId: string) {
  if (item.lesson_id) return `/lessons/${item.lesson_id}?session=${sessionId}`;
  if (item.exercise_id) return `/practice/${item.exercise_id}?session=${sessionId}`;
  if (item.activity_type === "vocabulary_review") return `/vocabulary/review?session=${sessionId}`;
  if (item.writing_task_id) return `/practice/writing/${item.writing_task_id}?session=${sessionId}`;
  if (item.activity_type === "conversation") return `/tutor?session=${sessionId}${item.scenario ? `&scenario=${item.scenario}` : ""}`;
  return `/learning/session/${sessionId}`;
}

export function PlanItemCard({ item, profileId, paused, compact = false }: { item: PlanItem; profileId: string; paused: boolean; compact?: boolean }) {
  const { t } = useI18n();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const startAssessment = useStartAssessment();
  const invalidate = useInvalidateProfile();
  const finished = item.status === "completed" || item.status === "skipped";

  async function start() {
    setBusy(true);
    try {
      if (item.activity_type === "placement_assessment") {
        const attempt = await startAssessment.mutateAsync(profileId);
        router.push(`/assessment/${attempt.id}`);
        return;
      }
      const { data } = await api<{ data: LearningSession }>(`/plan-items/${item.id}/start`, { method: "POST" });
      invalidate(profileId);
      router.push(planItemHref(item, data.id));
    } catch (e) {
      toastError(e, t.common.errorTitle);
      setBusy(false);
    }
  }

  async function skip() {
    try {
      await api(`/plan-items/${item.id}/skip`, { method: "POST", body: {} });
      invalidate(profileId);
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  return (
    <li className={cn("rounded-xl border bg-card transition-colors", finished && "bg-muted/40")}>
      <div className="flex items-center gap-3 p-4">
        {item.skill ? (
          <SkillIcon skill={item.skill} />
        ) : (
          <span className="grid size-9 place-items-center rounded-lg bg-accent/20 text-accent-foreground dark:text-accent">
            {item.activity_type === "revision" || item.activity_type === "vocabulary_review" ? <RotateCcw className="size-[18px]" /> : <ClipboardCheck className="size-[18px]" />}
          </span>
        )}
        <div className="min-w-0 flex-1">
          <p className={cn("truncate font-medium", finished && "text-muted-foreground line-through decoration-1")}>{item.title}</p>
          <p className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
            <Clock className="size-3" aria-hidden /> {item.estimated_minutes} {t.common.minutes}
            {item.target_level && <Badge variant="outline" className="px-1.5 py-0 font-mono text-[10px]">{item.target_level.label}</Badge>}
          </p>
        </div>
        {item.status === "completed" ? (
          <span className="flex items-center gap-1 text-sm text-success"><Check className="size-4" /> {t.home.done}</span>
        ) : item.status === "skipped" ? (
          <span className="text-sm text-muted-foreground">{t.learning.status.skipped}</span>
        ) : (
          <Button size="sm" onClick={start} loading={busy} disabled={paused}>
            {item.status === "in_progress" ? t.home.continue : t.home.start}
          </Button>
        )}
      </div>
      {!compact && (
        <div className="border-t px-4 py-2">
          <button className="flex w-full items-center justify-between text-xs text-muted-foreground hover:text-foreground" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
            {t.home.why}
            <ChevronDown className={cn("size-4 transition-transform", open && "rotate-180")} aria-hidden />
          </button>
          {open && (
            <div className="space-y-2 pb-2 pt-2 text-sm">
              <p className="text-muted-foreground">{item.reason}</p>
              <p>{item.objective}</p>
              {!finished && item.activity_type !== "placement_assessment" && (
                <Button variant="link" size="sm" className="h-auto text-xs" onClick={skip} disabled={paused}>{t.learning.skip}</Button>
              )}
            </div>
          )}
        </div>
      )}
    </li>
  );
}
