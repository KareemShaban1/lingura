"use client";

import { useState } from "react";
import { History, Pause, Play, RefreshCw, Sparkles } from "lucide-react";
import { Bar, BarChart, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { toast } from "sonner";
import { skillVar } from "@/components/skill";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Select } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { useActiveProfile, useCatalog, useInvalidateProfile, usePlan, usePlanAction, usePlanItems, usePlanVersions } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import type { PlanItem } from "@/lib/types";
import { localDate } from "@/lib/utils";
import { PlanItemCard } from "./plan-item-card";

function StudySettings({ profileId, dailyMinutes, purpose }: { profileId: string; dailyMinutes: number; purpose: string }) {
  const { t } = useI18n();
  const catalog = useCatalog();
  const invalidate = useInvalidateProfile();
  const [values, setValues] = useState({ daily_minutes: dailyMinutes, purpose });
  const [saving, setSaving] = useState(false);
  const dirty = values.daily_minutes !== dailyMinutes || values.purpose !== purpose;
  const minuteOptions = Array.from(new Set([...(catalog.data?.daily_minutes.suggested ?? [10, 15, 20, 30, 45, 60]), dailyMinutes, 90, 120])).sort((a, b) => a - b);

  async function save() {
    setSaving(true);
    try {
      await api(`/learner-profiles/${profileId}`, { method: "PATCH", body: values });
      invalidate(profileId);
      toast.success(t.common.saved);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t.common.errorTitle);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader><CardTitle>{t.learning.settings}</CardTitle></CardHeader>
      <CardContent className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="daily">{t.learning.dailyMinutes}</Label>
          <Select id="daily" value={values.daily_minutes} onChange={(e) => setValues((v) => ({ ...v, daily_minutes: Number(e.target.value) }))}>
            {minuteOptions.map((m) => <option key={m} value={m}>{m} {t.common.minutesLong}</option>)}
          </Select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="purpose">{t.learning.goal}</Label>
          <Select id="purpose" value={values.purpose} onChange={(e) => setValues((v) => ({ ...v, purpose: e.target.value }))}>
            {catalog.data?.purposes.map((p) => <option key={p.slug} value={p.slug}>{t.purposes[p.slug as keyof typeof t.purposes] ?? p.name}</option>)}
          </Select>
        </div>
        <Button onClick={save} disabled={!dirty} loading={saving} className="w-full">{t.common.save}</Button>
      </CardContent>
    </Card>
  );
}

export function LearningView() {
  const { t, f, locale } = useI18n();
  const profile = useActiveProfile();
  const plan = usePlan(profile?.id);
  const items = usePlanItems(profile?.id);
  const versions = usePlanVersions(profile?.id);
  const action = usePlanAction(profile?.id ?? "");

  if (plan.error) return <ErrorState error={plan.error} onRetry={() => plan.refetch()} />;
  if (!profile || plan.isPending || items.isPending) return <div className="space-y-4"><Skeleton className="h-10 w-1/3" /><Skeleton className="h-48" /><Skeleton className="h-64" /></div>;
  if (!plan.data?.current_version) return <EmptyState title={t.learning.noPlan} />;

  const version = plan.data.current_version;
  const paused = plan.data.status === "paused";
  const today = localDate();
  const byDate = (items.data ?? []).reduce<Record<string, PlanItem[]>>((acc, item) => {
    (acc[item.scheduled_date] ??= []).push(item);
    return acc;
  }, {});
  const dayLabel = (date: string) =>
    new Date(`${date}T12:00:00`).toLocaleDateString(locale === "ar" ? "ar" : "en", { weekday: "long", day: "numeric", month: "short" });
  const allocation = (version.allocations ?? []).map((a) => ({ ...a, label: t.skills[a.skill] }));

  const run = (kind: "pause" | "resume" | "regenerate") =>
    action.mutate(kind, { onError: (e) => toast.error(e.message), onSuccess: () => kind === "regenerate" && toast.success(t.common.saved) });

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-sm text-muted-foreground">{f(t.learning.version, { n: version.version_number })} · {t.learning.trigger[version.trigger as keyof typeof t.learning.trigger] ?? version.trigger}</p>
          <h1 className="font-display text-3xl md:text-4xl">{t.learning.title}</h1>
        </div>
        <div className="flex gap-2">
          {paused ? (
            <Button variant="outline" onClick={() => run("resume")} loading={action.isPending}><Play /> {t.learning.resume}</Button>
          ) : (
            <Button variant="outline" onClick={() => run("pause")} loading={action.isPending}><Pause /> {t.learning.pause}</Button>
          )}
          <Dialog>
            <DialogTrigger asChild><Button variant="secondary"><RefreshCw /> {t.learning.regenerate}</Button></DialogTrigger>
            <DialogContent>
              <DialogTitle>{t.learning.regenerate}</DialogTitle>
              <DialogDescription>{t.learning.versions}: {f(t.learning.version, { n: version.version_number + 1 })}</DialogDescription>
              <div className="mt-6 flex justify-end gap-2">
                <DialogClose asChild><Button variant="outline">{t.common.cancel}</Button></DialogClose>
                <DialogClose asChild><Button onClick={() => run("regenerate")}>{t.learning.regenerate}</Button></DialogClose>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>

      {paused && <div className="rounded-xl border border-warning/50 bg-warning/10 p-4 text-sm">{t.learning.pausedBanner}</div>}

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <section aria-labelledby="roadmap" className="space-y-6">
          <h2 id="roadmap" className="text-lg font-semibold">{t.learning.roadmap}</h2>
          {Object.entries(byDate).map(([date, dayItems]) => (
            <div key={date}>
              <h3 className="mb-2 flex items-center gap-2 text-sm font-medium text-muted-foreground">
                {dayLabel(date)} {date === today && <Badge variant="accent">{t.common.today}</Badge>}
                <span className="ms-auto text-xs">{dayItems.reduce((n, i) => n + i.estimated_minutes, 0)} {t.common.minutes}</span>
              </h3>
              <ul className="space-y-2">{dayItems.map((item) => <PlanItemCard key={item.id} item={item} profileId={profile.id} paused={paused} />)}</ul>
            </div>
          ))}
        </section>

        <aside className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2"><Sparkles className="size-4 text-accent" aria-hidden /> {t.learning.rationale}</CardTitle>
              <CardDescription>{version.rationale}</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="mb-2 text-xs font-medium text-muted-foreground">{t.learning.allocation}</p>
              <div className="h-48" role="img" aria-label={allocation.map((a) => `${a.label}: ${a.minutes_per_week} ${t.common.minutes}`).join(", ")}>
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={allocation} layout="vertical" margin={{ left: 0, right: 8 }}>
                    <XAxis type="number" hide reversed={locale === "ar"} />
                    <YAxis type="category" dataKey="label" width={84} tickLine={false} axisLine={false} fontSize={12} orientation={locale === "ar" ? "right" : "left"} />
                    <Tooltip cursor={{ fill: "var(--muted)" }} formatter={(v) => [`${v} ${t.common.minutes}`, ""]} contentStyle={{ borderRadius: 8, fontSize: 12 }} />
                    <Bar dataKey="minutes_per_week" radius={4}>
                      {allocation.map((a) => <Cell key={a.skill} fill={skillVar[a.skill]} />)}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              </div>
              <ul className="mt-3 space-y-1.5 text-xs text-muted-foreground">
                {allocation.map((a) => <li key={a.skill}><span className="font-medium text-foreground">{a.label}</span> — {a.reason}</li>)}
              </ul>
            </CardContent>
          </Card>

          <StudySettings key={`${profile.daily_minutes}-${profile.purpose.slug}`} profileId={profile.id} dailyMinutes={profile.daily_minutes} purpose={profile.purpose.slug} />

          <Card>
            <CardHeader><CardTitle className="flex items-center gap-2"><History className="size-4" aria-hidden /> {t.learning.versions}</CardTitle></CardHeader>
            <CardContent>
              <ol className="space-y-3">
                {versions.data?.map((v) => (
                  <li key={v.version_number} className="border-s-2 ps-3 text-sm">
                    <p className="font-medium">{f(t.learning.version, { n: v.version_number })} <span className="font-normal text-muted-foreground">· {new Date(v.created_at).toLocaleDateString(locale === "ar" ? "ar" : "en")}</span></p>
                    <p className="text-xs text-muted-foreground">
                      {t.learning.trigger[v.trigger as keyof typeof t.learning.trigger] ?? v.trigger} · {f(t.learning.completedOf, { done: v.items_completed ?? 0, total: v.items_total ?? 0 })}
                    </p>
                  </li>
                ))}
              </ol>
            </CardContent>
          </Card>
        </aside>
      </div>
    </div>
  );
}
