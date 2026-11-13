"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useQueryClient } from "@tanstack/react-query";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import {
  Award, BookOpen, CheckCircle2, Dumbbell, Flame, Gem, Layers, Library, Lock, MessageCircle, MessagesSquare, PenLine, Snowflake, Star, Target, Trophy,
  type LucideIcon,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import { ErrorState } from "@/components/ui/states";
import { Switch } from "@/components/ui/checkbox";
import { useDate } from "@/features/billing/billing-views";
import { useAchievements, useChallenges, useGamification, useLeaderboard, useXpHistory } from "@/features/queries";
import { api } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import type { GamificationSummary } from "@/lib/types";
import { cn } from "@/lib/utils";

const ICONS: Record<string, LucideIcon> = {
  "book-open": BookOpen, library: Library, dumbbell: Dumbbell, trophy: Trophy, layers: Layers, gem: Gem, flame: Flame,
  "message-circle": MessageCircle, "messages-square": MessagesSquare, "pen-line": PenLine, target: Target, star: Star,
};

/** Compact XP + streak card for the dashboard. */
export function RewardsWidget() {
  const { t, f } = useI18n();
  const game = useGamification();
  if (!game.data) return <Skeleton className="h-28" />;
  const g = game.data;
  return (
    <Link href="/rewards" className="group block">
      <Card className="h-full group-hover:border-primary/50">
        <CardContent className="flex items-center gap-4 p-5">
          <Flame className={g.streak.current_days ? "size-8 text-accent" : "size-8 text-muted-foreground"} aria-hidden />
          <div className="flex-1">
            <p className="font-display text-2xl leading-none">{f(t.rewards.streakDays, { n: g.streak.current_days })}</p>
            <p className="mt-1 text-xs text-muted-foreground">
              {g.streak.active_today ? t.rewards.activeToday : g.streak.at_risk ? t.rewards.atRisk : f(t.rewards.freezes, { n: g.streak.freezes_available })}
            </p>
          </div>
          <div className="text-end">
            <p className="font-display text-2xl leading-none tabular-nums">{g.xp_today}</p>
            <p className="mt-1 text-xs text-muted-foreground">XP · {t.rewards.xpToday}</p>
          </div>
        </CardContent>
      </Card>
    </Link>
  );
}

function StreakCard({ g }: { g: GamificationSummary }) {
  const { t, f } = useI18n();
  const history = useXpHistory();
  const date = useDate();
  const days = useMemo(() => {
    const kinds = new Map((history.data?.streak_days ?? []).map((d) => [d.date, d.kind]));
    return Array.from({ length: 30 }, (_, i) => {
      const d = new Date();
      d.setDate(d.getDate() - (29 - i));
      const key = d.toLocaleDateString("en-CA");
      return { key, kind: kinds.get(key) };
    });
  }, [history.data]);

  return (
    <Card>
      <CardContent className="space-y-4 p-5">
        <div className="flex items-center gap-3">
          <Flame className={cn("size-10", g.streak.current_days ? "text-accent" : "text-muted-foreground")} aria-hidden />
          <div>
            <p className="font-display text-3xl leading-none">{f(t.rewards.streakDays, { n: g.streak.current_days })}</p>
            <p className="mt-1 text-sm text-muted-foreground">{f(t.rewards.longest, { n: g.streak.longest_days })}</p>
          </div>
        </div>
        <p className={cn("text-sm", g.streak.at_risk && !g.streak.active_today && "font-medium text-foreground")}>
          {g.streak.active_today ? t.rewards.activeToday : t.rewards.atRisk}
        </p>
        <div className="flex flex-wrap gap-1" aria-hidden>
          {days.map((d) => (
            <span key={d.key} title={d.kind === "frozen" ? `${date(d.key)} · ${t.rewards.frozenDay}` : date(d.key)}
              className={cn("size-3.5 rounded-sm", d.kind === "active" ? "bg-accent" : d.kind === "frozen" ? "bg-sky-400/70" : "bg-muted")} />
          ))}
        </div>
        <p className="flex items-start gap-2 text-xs text-muted-foreground">
          <Snowflake className="mt-0.5 size-3.5 shrink-0 text-sky-500" aria-hidden />
          <span><strong className="text-foreground">{f(t.rewards.freezes, { n: g.streak.freezes_available })}.</strong> {t.rewards.freezeHelp}</span>
        </p>
      </CardContent>
    </Card>
  );
}

function XpChart() {
  const { t, locale } = useI18n();
  const history = useXpHistory();
  const date = useDate();
  const data = useMemo(() => {
    const totals = new Map<string, number>();
    for (const r of history.data?.daily ?? []) totals.set(r.date, (totals.get(r.date) ?? 0) + r.xp);
    return Array.from({ length: 30 }, (_, i) => {
      const d = new Date();
      d.setDate(d.getDate() - (29 - i));
      const key = d.toLocaleDateString("en-CA");
      return { date: key, xp: totals.get(key) ?? 0 };
    });
  }, [history.data]);
  if (history.error) return <ErrorState error={history.error} onRetry={() => history.refetch()} />;
  return (
    <Card>
      <CardHeader><CardTitle className="text-base">{t.rewards.last30}</CardTitle></CardHeader>
      <CardContent>
        {!history.data ? <Skeleton className="h-44" /> : (
          <div className="h-44">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data} margin={{ top: 8, right: 8, left: -20, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="date" tickFormatter={(v) => date(String(v)).replace(/\s\d{4}$/, "")} fontSize={10} tickLine={false} reversed={locale === "ar"} minTickGap={24} />
                <YAxis fontSize={11} tickLine={false} axisLine={false} allowDecimals={false} orientation={locale === "ar" ? "right" : "left"} />
                <Tooltip labelFormatter={(l) => date(String(l))} formatter={(v) => [`${v} XP`, ""]} contentStyle={{ borderRadius: 8, fontSize: 12 }} />
                <Bar dataKey="xp" fill="var(--primary)" radius={[3, 3, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function Achievements() {
  const { t, f } = useI18n();
  const list = useAchievements();
  const date = useDate();
  if (list.error) return <ErrorState error={list.error} onRetry={() => list.refetch()} />;
  if (!list.data) return <Skeleton className="h-64" />;
  const unlocked = list.data.filter((a) => a.unlocked_at).length;
  return (
    <section className="space-y-3">
      <div className="flex items-baseline justify-between">
        <h2 className="text-lg font-semibold">{t.rewards.achievements}</h2>
        <span className="text-sm text-muted-foreground">{f(t.rewards.unlocked, { n: unlocked, total: list.data.length })}</span>
      </div>
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {list.data.map((a) => {
          const Icon = ICONS[a.icon] ?? Award;
          const done = !!a.unlocked_at;
          return (
            <li key={a.slug}>
              <Card className={cn("h-full", !done && "opacity-80")}>
                <CardContent className="flex gap-3 p-4">
                  <span className={cn("grid size-10 shrink-0 place-items-center rounded-lg", done ? "bg-accent/20 text-accent-foreground dark:text-accent" : "bg-muted text-muted-foreground")}>
                    {done ? <Icon className="size-5" /> : <Lock className="size-4" />}
                  </span>
                  <div className="min-w-0 flex-1 space-y-1">
                    <p className="font-medium leading-tight">{a.title}</p>
                    <p className="text-xs text-muted-foreground">{a.description}</p>
                    {done ? (
                      <p className="text-xs text-success">{f(t.rewards.unlockedOn, { date: date(a.unlocked_at) })}</p>
                    ) : (
                      <div className="flex items-center gap-2 pt-1">
                        <Progress value={(a.progress / a.threshold) * 100} className="h-1.5" />
                        <span className="shrink-0 text-[11px] tabular-nums text-muted-foreground">{a.progress}/{a.threshold}</span>
                      </div>
                    )}
                  </div>
                  {a.xp_reward > 0 && <Badge variant="outline" className="self-start">{f(t.rewards.reward, { xp: a.xp_reward })}</Badge>}
                </CardContent>
              </Card>
            </li>
          );
        })}
      </ul>
    </section>
  );
}

function Challenges() {
  const { t, f } = useI18n();
  const list = useChallenges();
  const date = useDate();
  if (list.error) return <ErrorState error={list.error} onRetry={() => list.refetch()} />;
  if (!list.data) return <Skeleton className="h-40" />;
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{t.rewards.challenges}</CardTitle>
        {list.data[0] && <CardDescription>{f(t.rewards.endsOn, { date: date(list.data[0].ends_on) })}</CardDescription>}
      </CardHeader>
      <CardContent>
        <ul className="space-y-4">
          {list.data.map((c) => (
            <li key={c.slug} className="space-y-1.5">
              <div className="flex items-center justify-between gap-2 text-sm">
                <span className="flex items-center gap-1.5 font-medium">
                  {c.completed && <CheckCircle2 className="size-4 text-success" aria-label={t.rewards.done} />}
                  {f(t.rewards.challengeTitles[c.metric], { target: c.target })}
                </span>
                <span className="text-xs text-muted-foreground">{f(t.rewards.reward, { xp: c.xp_reward })}</span>
              </div>
              <div className="flex items-center gap-2">
                <Progress value={(c.progress / c.target) * 100} className="h-2" indicatorClassName={c.completed ? "bg-success" : undefined} />
                <span className="shrink-0 text-xs tabular-nums text-muted-foreground">{c.progress}/{c.target}</span>
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function LeaderboardCard({ g }: { g: GamificationSummary }) {
  const { t, f } = useI18n();
  const board = useLeaderboard();
  const qc = useQueryClient();
  const [alias, setAlias] = useState("");
  const [busy, setBusy] = useState(false);

  async function save(optIn: boolean) {
    setBusy(true);
    try {
      const { data } = await api<{ data: GamificationSummary }>("/me/leaderboard", { method: "PATCH", body: { opt_in: optIn, alias: alias.trim() || null } });
      qc.setQueryData(["gamification"], data);
      qc.invalidateQueries({ queryKey: ["gamification", "leaderboard"] });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader><CardTitle className="flex items-center gap-2 text-base"><Trophy className="size-4 text-accent" /> {t.rewards.leaderboard}</CardTitle></CardHeader>
      <CardContent className="space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <Label htmlFor="lb-opt">{t.rewards.optIn}</Label>
            <p className="mt-1 text-xs text-muted-foreground">{t.rewards.privacy}</p>
          </div>
          <Switch id="lb-opt" checked={g.leaderboard_opt_in} disabled={busy} onCheckedChange={(v) => save(v)} />
        </div>
        {g.leaderboard_opt_in && (
          <form className="flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); save(true); }}>
            <div className="flex-1 space-y-1">
              <Label htmlFor="lb-alias" className="text-xs">{t.rewards.alias}</Label>
              <Input id="lb-alias" value={alias} onChange={(e) => setAlias(e.target.value)} maxLength={40} placeholder={t.rewards.aliasHelp} />
            </div>
            <Button type="submit" size="sm" variant="outline" loading={busy}>{t.common.save}</Button>
          </form>
        )}
        {board.error ? <ErrorState error={board.error} onRetry={() => board.refetch()} /> : !board.data ? <Skeleton className="h-32" /> : board.data.entries.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t.rewards.emptyBoard}</p>
        ) : (
          <ol className="divide-y rounded-lg border text-sm">
            {board.data.entries.slice(0, 10).map((e) => (
              <li key={e.rank} className={cn("flex items-center gap-3 px-3 py-2", e.is_you && "bg-secondary/60 font-medium")}>
                <span className="w-8 tabular-nums text-muted-foreground">{f(t.rewards.rank, { n: e.rank })}</span>
                <span className="flex-1 truncate">{e.name}</span>
                <span className="tabular-nums">{e.xp} XP</span>
              </li>
            ))}
          </ol>
        )}
        {board.data?.you && board.data.you.rank > 10 && <p className="text-sm">{f(t.rewards.rank, { n: board.data.you.rank })} · {board.data.you.xp} XP</p>}
        {!g.leaderboard_opt_in && <p className="text-xs text-muted-foreground">{t.rewards.notOnBoard}</p>}
      </CardContent>
    </Card>
  );
}

export function RewardsView() {
  const { t } = useI18n();
  const game = useGamification();
  if (game.error) return <ErrorState error={game.error} onRetry={() => game.refetch()} />;
  if (!game.data) return <Skeleton className="h-[40rem]" />;
  const g = game.data;
  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-display text-3xl md:text-4xl">{t.rewards.title}</h1>
        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">{t.rewards.xpHow}</p>
      </div>
      <div className="grid grid-cols-3 gap-3">
        {([["xpTotal", g.xp_total], ["xpWeek", g.xp_week], ["xpToday", g.xp_today]] as const).map(([k, v]) => (
          <Card key={k}><CardContent className="p-4"><p className="font-display text-2xl tabular-nums md:text-3xl">{v}</p><p className="text-xs text-muted-foreground">XP · {t.rewards[k]}</p></CardContent></Card>
        ))}
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        <StreakCard g={g} />
        <Challenges />
      </div>
      <XpChart />
      <Achievements />
      <LeaderboardCard g={g} />
    </div>
  );
}
