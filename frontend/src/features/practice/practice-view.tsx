"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowRight, Clock, Puzzle } from "lucide-react";
import { SkillIcon } from "@/components/skill";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { useActiveProfile, useExercises } from "@/features/queries";
import { useI18n } from "@/lib/i18n";
import type { ExerciseSummary, SkillSlug } from "@/lib/types";
import { cn } from "@/lib/utils";

export function ExerciseCard({ exercise, href }: { exercise: ExerciseSummary; href: string }) {
  const { t, f } = useI18n();
  return (
    <Link href={href} className="group block">
      <Card className="h-full transition-colors group-hover:border-primary/50">
        <CardContent className="flex h-full items-start gap-3 p-4">
          <SkillIcon skill={exercise.skill} />
          <div className="min-w-0 flex-1">
            <p className="font-medium">{exercise.title ?? t.practice.types[exercise.type]}</p>
            <p className="mt-0.5 text-xs text-muted-foreground">{t.practice.types[exercise.type]} · {f(t.practice.questions, { n: exercise.questions_count })}</p>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
              <Badge variant="outline" className="font-mono">{exercise.level.label}</Badge>
              <span className="flex items-center gap-1 text-muted-foreground"><Clock className="size-3" /> {exercise.estimated_minutes} {t.common.minutes}</span>
              {exercise.last_score != null && <Badge variant={exercise.last_score >= 0.8 ? "success" : "warning"}>{f(t.practice.lastScore, { score: Math.round(exercise.last_score * 100) })}</Badge>}
            </div>
          </div>
          <ArrowRight className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" aria-hidden />
        </CardContent>
      </Card>
    </Link>
  );
}

const FILTERS: (SkillSlug | "all")[] = ["all", "grammar", "vocabulary", "reading", "listening"];

export function PracticeView() {
  const { t } = useI18n();
  const profile = useActiveProfile();
  const [skill, setSkill] = useState<SkillSlug | "all">("all");
  const exercises = useExercises(profile?.id, skill === "all" ? undefined : skill);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="font-display text-3xl md:text-4xl">{t.practice.title}</h1>
          <p className="mt-1 text-muted-foreground">{t.practice.subtitle}</p>
        </div>
        <Link href="/practice/grammar" className="flex items-center gap-2 rounded-lg border bg-card px-4 py-2 text-sm font-medium hover:border-primary/50">
          <Puzzle className="size-4 text-skill-grammar" /> {t.practice.grammarTopics}
        </Link>
      </div>

      <div role="tablist" className="flex gap-2 overflow-x-auto pb-1">
        {FILTERS.map((s) => (
          <button key={s} role="tab" aria-selected={skill === s} onClick={() => setSkill(s)}
            className={cn("shrink-0 rounded-full border px-4 py-1.5 text-sm", skill === s ? "border-primary bg-primary text-primary-foreground" : "bg-card hover:bg-muted")}>
            {s === "all" ? t.practice.all : t.skills[s]}
          </button>
        ))}
      </div>

      {exercises.error ? <ErrorState error={exercises.error} onRetry={() => exercises.refetch()} />
        : !exercises.data ? <div className="grid gap-3 sm:grid-cols-2">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-28" />)}</div>
        : exercises.data.length === 0 ? <EmptyState title={t.practice.empty} body={skill === "listening" ? t.practice.audioPending : undefined} />
        : (
          <div className="grid gap-3 sm:grid-cols-2">
            {exercises.data.map((e) => <ExerciseCard key={e.id} exercise={e} href={`/practice/${e.id}`} />)}
          </div>
        )}
      {skill === "all" && exercises.data && !exercises.data.some((e) => e.type === "listening") && (
        <p className="text-xs text-muted-foreground">{t.practice.audioPending}</p>
      )}
    </div>
  );
}
