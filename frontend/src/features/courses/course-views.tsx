"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Check, CheckCircle2, Circle, CircleDot, Target } from "lucide-react";
import { toast } from "sonner";
import { SkillIcon } from "@/components/skill";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { useActivitySession } from "@/features/practice/use-activity-session";
import { useActiveProfile, useCourses, useInvalidateProfile, useLesson } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import type { LessonSummary } from "@/lib/types";
import { cn } from "@/lib/utils";

const statusIcon = { not_started: Circle, in_progress: CircleDot, completed: CheckCircle2 } as const;

export function CoursesView() {
  const { t, f } = useI18n();
  const profile = useActiveProfile();
  const courses = useCourses(profile?.id);
  if (courses.error) return <ErrorState error={courses.error} onRetry={() => courses.refetch()} />;
  if (!courses.data) return <Skeleton className="h-96" />;
  if (courses.data.data.length === 0) return <EmptyState title={t.courses.empty} />;
  const next = courses.data.next_lesson_id;

  return (
    <div className="space-y-8">
      <Link href="/learning" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4 rtl:rotate-180" /> {t.nav.learning}</Link>
      {courses.data.data.map((course) => (
        <section key={course.id} className="space-y-6">
          <div>
            <p className="font-mono text-xs text-muted-foreground">{course.level_from.label} → {course.level_to.label}</p>
            <h1 className="font-display text-3xl md:text-4xl">{course.title}</h1>
            {course.description && <p className="mt-1 max-w-2xl text-muted-foreground">{course.description}</p>}
            <div className="mt-4 flex max-w-sm items-center gap-3">
              <Progress value={(course.lessons_completed / Math.max(1, course.lessons_total)) * 100} />
              <span className="shrink-0 text-xs text-muted-foreground">{f(t.courses.lessonsDone, { done: course.lessons_completed, total: course.lessons_total })}</span>
            </div>
          </div>
          {course.units.map((unit, u) => (
            <div key={u}>
              <h2 className="mb-3 text-sm font-semibold uppercase tracking-wider text-muted-foreground">{u + 1}. {unit.title}</h2>
              <ol className="space-y-2">
                {unit.lessons.map((lesson: LessonSummary) => {
                  const Icon = statusIcon[lesson.status];
                  return (
                    <li key={lesson.id}>
                      <Link href={`/lessons/${lesson.id}`} className={cn("flex items-center gap-3 rounded-xl border bg-card p-4 hover:border-primary/50", lesson.id === next && "border-primary shadow-[0_0_0_1px_var(--primary)]")}>
                        <Icon className={cn("size-5 shrink-0", lesson.status === "completed" ? "text-success" : "text-muted-foreground")} aria-label={t.courses.status[lesson.status]} />
                        <SkillIcon skill={lesson.skill} className="size-8" />
                        <div className="min-w-0 flex-1">
                          <p className="font-medium">{lesson.title}</p>
                          <p className="text-xs text-muted-foreground">{lesson.estimated_minutes} {t.common.minutes} · {lesson.level.label}</p>
                        </div>
                        {lesson.id === next && <Badge variant="accent">{t.courses.continue}</Badge>}
                      </Link>
                    </li>
                  );
                })}
              </ol>
            </div>
          ))}
        </section>
      ))}
    </div>
  );
}

export function LessonView() {
  const { lessonId } = useParams<{ lessonId: string }>();
  const planSession = useSearchParams().get("session");
  const { t } = useI18n();
  const profile = useActiveProfile();
  const lesson = useLesson(profile?.id, lessonId);
  const session = useActivitySession(profile?.id, "lesson", planSession, lesson.data?.status !== "completed");
  const invalidate = useInvalidateProfile();
  const qc = useQueryClient();
  const [completing, setCompleting] = useState(false);

  if (lesson.error) return <ErrorState error={lesson.error} onRetry={() => lesson.refetch()} />;
  if (!lesson.data || !profile) return <div className="space-y-4"><Skeleton className="h-10 w-1/2" /><Skeleton className="h-48" /><Skeleton className="h-48" /></div>;
  const l = lesson.data;
  const exercisesDone = l.exercises.filter((e) => e.completed || !e.available).length;
  const canComplete = exercisesDone === l.exercises.length;
  const sessionQuery = session.sessionId ? `&session=${session.sessionId}` : "";

  async function complete() {
    setCompleting(true);
    try {
      await api(`/learner-profiles/${profile!.id}/lessons/${lessonId}/complete`, { method: "POST" });
      await session.complete();
      toast.success(t.lesson.completed);
      invalidate(profile!.id);
      qc.invalidateQueries({ queryKey: ["profile", profile!.id] });
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t.common.errorTitle);
    } finally {
      setCompleting(false);
    }
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <Link href="/learning/courses" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4 rtl:rotate-180" /> {t.lesson.backToCourse}</Link>
      <div>
        <div className="flex items-center gap-2 text-xs text-muted-foreground"><Badge variant="outline" className="font-mono">{l.level.label}</Badge> {l.estimated_minutes} {t.common.minutes}</div>
        <h1 className="mt-2 font-display text-3xl md:text-4xl">{l.title}</h1>
        <p className="mt-2 text-lg text-muted-foreground">{l.intro}</p>
      </div>

      {l.objectives.length > 0 && (
        <Card className="bg-secondary/30">
          <CardContent className="p-5">
            <p className="mb-2 flex items-center gap-2 text-sm font-semibold"><Target className="size-4 text-primary" /> {t.lesson.objectives}</p>
            <ul className="space-y-1.5 text-sm">{l.objectives.map((o) => <li key={o} className="flex gap-2"><Check className="mt-0.5 size-4 shrink-0 text-primary" />{o}</li>)}</ul>
          </CardContent>
        </Card>
      )}

      {l.grammar_topic && (
        <Card>
          <CardHeader><CardTitle>{t.lesson.grammar}: {l.grammar_topic.title}</CardTitle></CardHeader>
          <CardContent className="space-y-4">
            <p className="leading-relaxed">{l.grammar_topic.explanation}</p>
            <ul className="space-y-2">
              {l.grammar_topic.examples?.map((e) => (
                <li key={e.sentence} className="rounded-lg bg-muted px-4 py-3">
                  <p lang={l.language.code} dir={l.language.direction} className="text-lg">{e.sentence}</p>
                  {e.translation && <p dir="auto" className="text-sm text-muted-foreground">{e.translation}</p>}
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}

      {l.words.length > 0 && (
        <Card>
          <CardHeader><CardTitle>{t.lesson.words}</CardTitle><p className="text-sm text-muted-foreground">{t.lesson.wordsNote}</p></CardHeader>
          <CardContent>
            <ul className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
              {l.words.map((w) => (
                <li key={w.id} className="border-b pb-2">
                  <p className="flex items-baseline justify-between gap-2">
                    <span lang={l.language.code} dir={l.language.direction} className="text-lg font-medium">{w.lemma}</span>
                    <span className="text-xs text-muted-foreground">{w.part_of_speech}{w.gender ? ` · ${w.gender}` : ""}</span>
                  </p>
                  <p className="text-sm text-muted-foreground">{w.meaning}</p>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}

      <section>
        <h2 className="mb-3 text-lg font-semibold">{t.lesson.exercises}</h2>
        <ol className="space-y-2">
          {l.exercises.map((e) => (
            <li key={e.id} className="flex items-center gap-3 rounded-xl border bg-card p-4">
              {e.completed ? <CheckCircle2 className="size-5 text-success" /> : <Circle className="size-5 text-muted-foreground" />}
              <div className="min-w-0 flex-1">
                <p className="font-medium">{e.title ?? t.practice.types[e.type]}</p>
                <p className="text-xs text-muted-foreground">{t.practice.types[e.type]}{e.score != null && ` · ${Math.round(e.score * 100)}%`}{!e.available && ` · ${t.practice.audioPending}`}</p>
              </div>
              {e.available && (
                <Button asChild size="sm" variant={e.completed ? "outline" : "default"}>
                  <Link href={`/practice/${e.id}?lesson=${l.id}${sessionQuery}`}>{e.completed ? t.lesson.retry : t.lesson.start}</Link>
                </Button>
              )}
            </li>
          ))}
        </ol>
      </section>

      <div className="flex flex-col items-start gap-2 border-t pt-6">
        {l.status === "completed" ? (
          <p className="flex items-center gap-2 font-medium text-success"><CheckCircle2 className="size-5" /> {t.lesson.completed}</p>
        ) : (
          <>
            <Button size="lg" onClick={complete} disabled={!canComplete} loading={completing}>{t.lesson.complete}</Button>
            {!canComplete && <p className="text-xs text-muted-foreground">{t.lesson.completeHint}</p>}
          </>
        )}
      </div>
    </div>
  );
}
