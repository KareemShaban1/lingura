"use client";

import Link from "next/link";
import { Clock, Flame } from "lucide-react";
import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { LevelLadder, SKILLS, SkillIcon, skillVar } from "@/components/skill";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { useActiveProfile, useProgress } from "@/features/queries";
import { useI18n } from "@/lib/i18n";
import type { SkillSlug } from "@/lib/types";
import { formatMinutes } from "@/lib/utils";

export function ProgressView() {
  const { t, locale } = useI18n();
  const profile = useActiveProfile();
  const progress = useProgress(profile?.id);
  const dateFmt = (iso: string) => new Date(iso).toLocaleDateString(locale === "ar" ? "ar" : "en", { day: "numeric", month: "short" });

  if (progress.error) return <ErrorState error={progress.error} onRetry={() => progress.refetch()} />;
  if (!progress.data) return <div className="space-y-4"><Skeleton className="h-10 w-1/3" /><Skeleton className="h-56" /><Skeleton className="h-56" /></div>;
  const p = progress.data;

  // One row per assessment date with each skill's scaled score (only real recorded scores).
  const historyRows = new Map<string, Record<string, number | string>>();
  for (const s of p.skills) {
    for (const h of s.history ?? []) {
      const key = h.recorded_at.slice(0, 10);
      const row = historyRows.get(key) ?? { date: key };
      row[s.skill] = h.scaled_score;
      historyRows.set(key, row);
    }
  }
  const history = [...historyRows.values()].sort((a, b) => String(a.date).localeCompare(String(b.date)));
  const assessedSkills = p.skills.filter((s) => (s.history?.length ?? 0) > 0).map((s) => s.skill);
  const practice = SKILLS.map((s) => ({ skill: s, label: t.skills[s], minutes: p.practice_by_skill[s]?.minutes ?? 0 })).filter((x) => x.minutes > 0);

  return (
    <div className="space-y-8">
      <h1 className="font-display text-3xl md:text-4xl">{t.progress.title}</h1>

      <div className="grid gap-4 sm:grid-cols-2">
        <Card><CardContent className="flex items-center gap-4 p-5"><Clock className="size-7 text-primary" aria-hidden /><div><p className="font-display text-3xl leading-none">{formatMinutes(p.total_minutes)}</p><p className="mt-1 text-xs text-muted-foreground">{t.progress.totalTime}</p></div></CardContent></Card>
        <Card><CardContent className="flex items-center gap-4 p-5"><Flame className="size-7 text-accent" aria-hidden /><div><p className="font-display text-3xl leading-none">{p.streak.current_days}</p><p className="mt-1 text-xs text-muted-foreground">{t.progress.streak}</p></div></CardContent></Card>
      </div>

      <Card>
        <CardHeader><CardTitle>{t.progress.skillLevels}</CardTitle></CardHeader>
        <CardContent className="grid gap-x-8 gap-y-5 sm:grid-cols-2">
          {SKILLS.map((skill) => {
            const sp = p.skills.find((s) => s.skill === skill);
            const assessed = sp?.status === "assessed";
            return (
              <div key={skill} className="flex items-center gap-3">
                <SkillIcon skill={skill} />
                <div className="flex-1">
                  <div className="flex justify-between text-sm">
                    <span className="font-medium">{t.skills[skill]}</span>
                    {assessed ? (
                      <span className="flex items-center gap-2">
                        {sp?.confidence && <span className="text-xs text-muted-foreground">{t.results.confidence[sp.confidence]}</span>}
                        <span className="font-mono">{sp?.level?.label}</span>
                      </span>
                    ) : (
                      <span className="text-xs text-muted-foreground">{t.common.notAssessed}</span>
                    )}
                  </div>
                  <LevelLadder ordinal={assessed ? sp?.level?.ordinal ?? null : null} skill={skill} className="mt-2" />
                </div>
              </div>
            );
          })}
        </CardContent>
      </Card>

      {history.length > 0 && (
        <Card>
          <CardHeader><CardTitle>{t.progress.scoreHistory}</CardTitle></CardHeader>
          <CardContent>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={history} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                  <XAxis dataKey="date" tickFormatter={dateFmt} fontSize={11} tickLine={false} reversed={locale === "ar"} />
                  <YAxis domain={[0, 100]} fontSize={11} tickLine={false} axisLine={false} orientation={locale === "ar" ? "right" : "left"} />
                  <Tooltip labelFormatter={(l) => dateFmt(String(l))} contentStyle={{ borderRadius: 8, fontSize: 12 }} />
                  <Legend formatter={(v) => t.skills[v as SkillSlug]} wrapperStyle={{ fontSize: 12 }} />
                  {assessedSkills.map((s) => <Line key={s} type="monotone" dataKey={s} stroke={skillVar[s]} strokeWidth={2} dot={{ r: 3 }} connectNulls />)}
                </LineChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader><CardTitle>{t.progress.activity}</CardTitle></CardHeader>
        <CardContent>
          <div className="h-48">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={p.weekly_activity} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="week_start" tickFormatter={dateFmt} fontSize={11} tickLine={false} reversed={locale === "ar"} />
                <YAxis fontSize={11} tickLine={false} axisLine={false} orientation={locale === "ar" ? "right" : "left"} />
                <Tooltip
                  labelFormatter={(l) => dateFmt(String(l))}
                  formatter={(v, _n, item) => [`${v} ${t.common.minutes} · ${(item.payload as { active_days: number }).active_days} ${t.progress.activeDays}`, ""]}
                  contentStyle={{ borderRadius: 8, fontSize: 12 }}
                />
                <Bar dataKey="minutes" fill="var(--primary)" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>{t.progress.practiceBySkill}</CardTitle></CardHeader>
          <CardContent>
            {practice.length ? (
              <ul className="space-y-3">
                {practice.map((x) => (
                  <li key={x.skill} className="flex items-center gap-3 text-sm">
                    <SkillIcon skill={x.skill} className="size-8" />
                    <span className="flex-1">{x.label}</span>
                    <span className="font-mono">{formatMinutes(x.minutes)}</span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted-foreground">{t.progress.noPractice}</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>{t.progress.history}</CardTitle></CardHeader>
          <CardContent>
            {p.attempts.length ? (
              <ul className="divide-y">
                {p.attempts.map((a) => (
                  <li key={a.id}>
                    <Link href={`/assessment/${a.id}/results`} className="flex items-center justify-between gap-3 py-3 text-sm hover:text-primary">
                      <span>{new Date(a.completed_at).toLocaleDateString(locale === "ar" ? "ar" : "en", { dateStyle: "medium" })}</span>
                      <span className="flex items-center gap-2">
                        {a.overall_is_provisional && <Badge variant="warning">{t.results.provisional}</Badge>}
                        <span className="font-mono text-base">{a.overall_level ?? "—"}</span>
                      </span>
                    </Link>
                  </li>
                ))}
              </ul>
            ) : (
              <EmptyState title={t.progress.noHistory} className="border-0 p-4" />
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
