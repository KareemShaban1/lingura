"use client";

import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { ErrorState } from "@/components/ui/states";
import { useMoney } from "@/features/billing/billing-views";
import { useI18n } from "@/lib/i18n";
import { AdminTitle, Table, useAdmin } from "./admin-shell";

type Overview = {
  users: { total: number; new_7d: number; new_30d: number; dau: number; wau: number; mau: number };
  subscriptions: { active_paid: number; complimentary: number; by_tier: Record<string, number>; mrr_minor: number; currency: string; cancel_scheduled: number; past_due: number };
  engagement_7d: { minutes: number; exercises: number; conversations: number; writings: number; reviews: number };
  ai_cost_30d: { total_micros: number; by_operation: { operation: string; cost_micros: number; calls: number; errors: number }[]; daily: { date: string; cost_micros: number; calls: number }[] };
  retention: { cohort: string; size: number; weeks: (number | null)[] }[];
  personalization?: { context: string; arm: string; decisions: number; resolved: number; mean_reward: number | null }[];
};

const usd = (micros: number) => `$${(micros / 1_000_000).toFixed(micros < 10_000_000 ? 4 : 2)}`;

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="font-display text-2xl tabular-nums">{value}</p>
      <p className="text-xs text-muted-foreground">{label}</p>
    </div>
  );
}

export function AdminOverview() {
  const { t } = useI18n();
  const o = t.admin.overview;
  const q = useAdmin<{ data: Overview }>("/analytics/overview");
  const money = useMoney();
  if (q.error) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  if (!q.data) return <Skeleton className="h-[40rem]" />;
  const d = q.data.data;

  return (
    <div className="space-y-6">
      <AdminTitle>{t.admin.nav.overview}</AdminTitle>
      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader><CardTitle className="text-base">{o.users}</CardTitle></CardHeader>
          <CardContent className="grid grid-cols-3 gap-4">
            <Stat label={o.total} value={d.users.total} /><Stat label={o.new7} value={d.users.new_7d} /><Stat label={o.dau} value={d.users.dau} />
            <Stat label={o.wau} value={d.users.wau} /><Stat label={o.mau} value={d.users.mau} />
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle className="text-base">{o.revenue}</CardTitle></CardHeader>
          <CardContent className="grid grid-cols-3 gap-4">
            <Stat label={o.mrr} value={money(d.subscriptions.mrr_minor, d.subscriptions.currency)} />
            <Stat label={o.paid} value={d.subscriptions.active_paid} /><Stat label={o.comp} value={d.subscriptions.complimentary} />
            <Stat label={o.cancelling} value={d.subscriptions.cancel_scheduled} /><Stat label={o.pastDue} value={d.subscriptions.past_due} />
            {Object.entries(d.subscriptions.by_tier).map(([tier, n]) => <Stat key={tier} label={t.billing.tiers[tier] ?? tier} value={n} />)}
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle className="text-base">{o.engagement}</CardTitle></CardHeader>
          <CardContent className="grid grid-cols-3 gap-4">
            <Stat label={o.minutes} value={d.engagement_7d.minutes} /><Stat label={o.exercises} value={d.engagement_7d.exercises} />
            <Stat label={o.conversations} value={d.engagement_7d.conversations} /><Stat label={o.writings} value={d.engagement_7d.writings} />
            <Stat label={o.reviews} value={d.engagement_7d.reviews} />
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader><CardTitle className="text-base">{o.aiCost}: {usd(d.ai_cost_30d.total_micros)}</CardTitle></CardHeader>
        <CardContent className="grid gap-6 lg:grid-cols-2">
          <div className="h-48" dir="ltr">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={d.ai_cost_30d.daily.map((r) => ({ ...r, usd: r.cost_micros / 1_000_000 }))} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="date" fontSize={10} tickLine={false} minTickGap={20} />
                <YAxis fontSize={10} tickLine={false} axisLine={false} />
                <Tooltip formatter={(v) => [`$${Number(v).toFixed(4)}`, o.cost]} contentStyle={{ borderRadius: 8, fontSize: 12 }} />
                <Bar dataKey="usd" fill="var(--primary)" radius={[3, 3, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
          <Table>
            <thead><tr><th>{o.operation}</th><th>{o.calls}</th><th>{o.errors}</th><th>{o.cost}</th></tr></thead>
            <tbody>
              {d.ai_cost_30d.by_operation.map((r) => (
                <tr key={r.operation}><td className="font-mono text-xs">{r.operation}</td><td>{r.calls}</td><td>{r.errors}</td><td className="tabular-nums">{usd(r.cost_micros)}</td></tr>
              ))}
            </tbody>
          </Table>
        </CardContent>
      </Card>

      {!!d.personalization?.length && (
        <Card>
          <CardHeader><CardTitle className="text-base">{o.bandit}</CardTitle></CardHeader>
          <CardContent>
            <Table>
              <thead><tr><th>{o.context}</th><th>{o.format}</th><th>{o.decisions}</th><th>{o.resolved}</th><th>{o.meanReward}</th></tr></thead>
              <tbody>{d.personalization.map((r) => (
                <tr key={`${r.context}-${r.arm}`}><td>{r.context}</td><td className="font-mono text-xs">{r.arm}</td><td>{r.decisions}</td><td>{r.resolved}</td><td>{r.mean_reward ?? "—"}</td></tr>
              ))}</tbody>
            </Table>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader><CardTitle className="text-base">{o.retention}</CardTitle></CardHeader>
        <CardContent>
          <Table>
            <thead><tr><th>{o.cohort}</th><th>{o.size}</th>{[0, 1, 2, 3, 4].map((n) => <th key={n}>{o.week.replace("{n}", String(n))}</th>)}</tr></thead>
            <tbody>
              {d.retention.map((r) => (
                <tr key={r.cohort}>
                  <td>{r.cohort}</td><td>{r.size}</td>
                  {[0, 1, 2, 3, 4].map((n) => {
                    const v = r.weeks[n];
                    return <td key={n} className="tabular-nums" style={v != null ? { background: `color-mix(in oklch, var(--primary) ${Math.round(v * 60)}%, transparent)` } : undefined}>{v == null ? "—" : `${Math.round(v * 100)}%`}</td>;
                  })}
                </tr>
              ))}
            </tbody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
