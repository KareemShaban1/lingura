"use client";

import Link from "next/link";
import { ArrowRight, ClipboardCheck, Flame, Layers, PauseCircle } from "lucide-react";
import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis } from "recharts";
import { LevelLadder, SKILLS, SkillIcon } from "@/components/skill";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { PlanItemCard } from "@/features/plan/plan-item-card";
import { useActiveProfile, useDashboard, useMe, usePlanAction, useStartAssessment } from "@/features/queries";
import { useI18n } from "@/lib/i18n";
import { useRouter } from "next/navigation";
import { toastError } from "@/lib/errors";
import { RewardsWidget } from "@/features/rewards/rewards-views";

export function DashboardView() {
  const { t, f, locale } = useI18n();
  const me = useMe();
  const features = me.data?.features ?? [];
  const profile = useActiveProfile();
  const dashboard = useDashboard(profile?.id);
  const startAssessment = useStartAssessment();
  const planAction = usePlanAction(profile?.id ?? "");
  const router = useRouter();

  if (dashboard.error) return <ErrorState error={dashboard.error} onRetry={() => dashboard.refetch()} />;
  if (!dashboard.data || !profile) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-10 w-1/2" />
        <div className="grid gap-4 md:grid-cols-3"><Skeleton className="h-28" /><Skeleton className="h-28" /><Skeleton className="h-28" /></div>
        <Skeleton className="h-64" />
      </div>
    );
  }
  const d = dashboard.data;
  const attempt = d.latest_attempt;
  const paused = d.plan_status === "paused";
  const firstName = me.data?.name.split(" ")[0] ?? "";
  const weekday = (date: string) => new Date(`${date}T12:00:00`).toLocaleDateString(locale === "ar" ? "ar" : "en", { weekday: "short" });

  async function goToAssessment() {
    try {
      const a = await startAssessment.mutateAsync(profile!.id);
      router.push(`/assessment/${a.id}`);
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  return (
    <div className="space-y-8">
      <div>
        <p className="text-sm text-muted-foreground" lang={profile.target_language.code}>{profile.target_language.native_name}</p>
        <h1 className="font-display text-3xl md:text-4xl">{f(t.home.greeting, { name: firstName })}</h1>
      </div>

      {(!attempt || attempt.status === "in_progress") && (
        <Card className="overflow-hidden border-primary/30 bg-secondary/40">
          <CardContent className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
            <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-primary text-primary-foreground"><ClipboardCheck className="size-6" aria-hidden /></span>
            <div className="flex-1">
              <p className="font-semibold">{attempt ? t.home.continueAssessment : t.home.takeAssessment}</p>
              <p className="text-sm text-muted-foreground">{t.home.takeAssessmentBody}</p>
            </div>
            <Button onClick={goToAssessment} loading={startAssessment.isPending}>
              {attempt ? t.home.continue : t.home.start} <ArrowRight className="rtl:rotate-180" />
            </Button>
          </CardContent>
        </Card>
      )}

      <div className="grid gap-4 sm:grid-cols-3">
        {features.includes("rewards") ? <RewardsWidget /> : (
          <Card>
            <CardContent className="flex items-center gap-4 p-5">
              <Flame className={d.streak.current_days ? "size-8 text-accent" : "size-8 text-muted-foreground"} aria-hidden />
              <div>
                <p className="font-display text-3xl leading-none">{d.streak.current_days}</p>
                <p className="mt-1 text-xs text-muted-foreground">{d.streak.current_days ? t.home.streak : t.home.streakNone}</p>
              </div>
            </CardContent>
          </Card>
        )}
        <Card>
          <CardContent className="p-5">
            <p className="text-xs text-muted-foreground">{t.common.today}</p>
            <p className="mt-1 text-sm font-medium">{f(t.home.minutesToday, { done: d.today_minutes_done, planned: d.today_minutes_planned })}</p>
            <Progress className="mt-3" value={d.today_minutes_planned ? (d.today_minutes_done / d.today_minutes_planned) * 100 : 0} />
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-5 pb-2">
            <div className="flex items-baseline justify-between">
              <p className="text-xs text-muted-foreground">{t.home.thisWeek}</p>
              <p className="text-sm font-medium">{d.week_minutes} {t.common.minutes}</p>
            </div>
            <div className="mt-2 h-16" aria-label={`${d.week_minutes} ${t.home.minutesStudied}`}>
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={d.week}>
                  <XAxis dataKey="date" tickFormatter={weekday} tickLine={false} axisLine={false} fontSize={10} reversed={locale === "ar"} interval={0} />
                  <Tooltip cursor={false} formatter={(v) => [`${v} ${t.common.minutes}`, ""]} labelFormatter={(l) => weekday(String(l))} contentStyle={{ borderRadius: 8, fontSize: 12 }} />
                  <Bar dataKey="minutes" fill="var(--primary)" radius={[3, 3, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>
      </div>

      {d.vocabulary_due > 0 && (
        <Link href="/vocabulary/review" className="flex items-center gap-4 rounded-xl border bg-card p-4 hover:border-primary/50">
          <span className="grid size-10 place-items-center rounded-lg bg-skill-vocabulary/15 text-skill-vocabulary"><Layers className="size-5" aria-hidden /></span>
          <span className="flex-1 font-medium">{f(t.vocab.review, { n: d.vocabulary_due })}</span>
          <ArrowRight className="size-4 text-muted-foreground rtl:rotate-180" aria-hidden />
        </Link>
      )}

      <section aria-labelledby="today">
        <div className="mb-3 flex items-center justify-between">
          <h2 id="today" className="text-lg font-semibold">{t.home.todayPlan}</h2>
          <Link href="/learning" className="text-sm text-primary hover:underline">{t.nav.learning}</Link>
        </div>
        {paused && (
          <div className="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-warning/50 bg-warning/10 p-4 text-sm">
            <span className="flex items-center gap-2"><PauseCircle className="size-4" aria-hidden /> {t.home.paused}</span>
            <Button size="sm" variant="outline" onClick={() => planAction.mutate("resume")} loading={planAction.isPending}>{t.home.resume}</Button>
          </div>
        )}
        {d.today_items.length ? (
          <ul className="space-y-3">{d.today_items.map((item) => <PlanItemCard key={item.id} item={item} profileId={profile.id} paused={paused} />)}</ul>
        ) : (
          <EmptyState title={t.home.todayEmpty} action={<Button asChild variant="outline" size="sm"><Link href="/learning">{t.nav.learning}</Link></Button>} />
        )}
      </section>

      <section aria-labelledby="skills">
        <div className="mb-3 flex items-center justify-between">
          <h2 id="skills" className="text-lg font-semibold">{t.home.skills}</h2>
          {attempt?.status === "completed" && <Link href={`/assessment/${attempt.id}/results`} className="text-sm text-primary hover:underline">{t.home.viewResults}</Link>}
        </div>
        <Card>
          <CardHeader className="sr-only"><CardTitle>{t.home.skills}</CardTitle></CardHeader>
          <CardContent className="grid gap-x-8 gap-y-5 p-5 sm:grid-cols-2">
            {SKILLS.map((skill) => {
              const sp = d.skills.find((s) => s.skill === skill);
              const assessed = sp?.status === "assessed";
              return (
                <div key={skill} className="flex items-center gap-3">
                  <SkillIcon skill={skill} />
                  <div className="flex-1">
                    <div className="flex justify-between text-sm">
                      <span className="font-medium">{t.skills[skill]}</span>
                      <span className={assessed ? "font-mono" : "text-xs text-muted-foreground"}>{assessed ? sp?.level?.label : t.common.notAssessed}</span>
                    </div>
                    <LevelLadder ordinal={assessed ? sp?.level?.ordinal ?? null : null} skill={skill} className="mt-2" />
                  </div>
                </div>
              );
            })}
          </CardContent>
        </Card>
      </section>
    </div>
  );
}
