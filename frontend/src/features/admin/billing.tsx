"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Switch } from "@/components/ui/checkbox";
import { Input, Select } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { useDate } from "@/features/billing/billing-views";
import { api } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import type { BillingPlan } from "@/lib/types";
import { AdminTitle, Pager, Table, useAdmin, type Paged } from "./admin-shell";

type AdminPlan = BillingPlan & { id: number; is_active: boolean; is_public: boolean; provider_price_id: string | null; subscribers: number };
type SubRow = { id: string; plan: string; status: string; provider: string; current_period_end: string | null; cancel_at_period_end: boolean; note: string | null; user: { id: number; name: string; email: string } | null };
type EventRow = { id: number; provider: string; event_id: string; type: string; status: string; error: string | null; created_at: string };

function PlanRow({ plan, features }: { plan: AdminPlan; features: string[] }) {
  const { t } = useI18n();
  const b = t.admin.billing;
  const qc = useQueryClient();
  const [price, setPrice] = useState(String(plan.price_minor));
  const [priceId, setPriceId] = useState(plan.provider_price_id ?? "");
  const [limits, setLimits] = useState<Record<string, string>>(() => Object.fromEntries(features.map((f) => [f, plan.limits[f as keyof typeof plan.limits] == null ? "" : String(plan.limits[f as keyof typeof plan.limits])])));

  async function save(body: Record<string, unknown>) {
    try {
      const res = await api(`/admin/billing/plans/${plan.id}`, { method: "PATCH", body });
      qc.setQueryData(["admin", "/billing/plans"], res);
      qc.invalidateQueries({ queryKey: ["billing"] });
      toast.success(t.admin.saved);
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  return (
    <Card>
      <CardHeader className="flex-row flex-wrap items-center justify-between gap-2 space-y-0">
        <CardTitle className="font-mono text-sm">{plan.slug} <Badge variant="muted" className="ms-1">{b.subscribers}: {plan.subscribers}</Badge></CardTitle>
        <div className="flex gap-4 text-sm">
          <label className="flex items-center gap-2">{b.active} <Switch checked={plan.is_active} onCheckedChange={(v) => save({ is_active: v })} /></label>
          <label className="flex items-center gap-2">{b.public} <Switch checked={plan.is_public} onCheckedChange={(v) => save({ is_public: v })} /></label>
        </div>
      </CardHeader>
      <CardContent>
        <form className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" onSubmit={(e) => {
          e.preventDefault();
          save({
            price_minor: Number(price), provider_price_id: priceId.trim() || null,
            limits: Object.fromEntries(Object.entries(limits).map(([k, v]) => [k, v === "" ? null : Number(v)])),
          });
        }}>
          <label className="space-y-1 text-xs">{b.price}<Input type="number" min={0} value={price} onChange={(e) => setPrice(e.target.value)} /></label>
          <label className="space-y-1 text-xs sm:col-span-1 lg:col-span-3">{b.priceId}<Input value={priceId} onChange={(e) => setPriceId(e.target.value)} placeholder="price_…" dir="ltr" /></label>
          {features.map((ft) => (
            <label key={ft} className="space-y-1 text-xs">{t.billing.featureNames[ft] ?? ft}
              <Input type="number" min={0} value={limits[ft]} placeholder={b.unlimitedHelp} onChange={(e) => setLimits({ ...limits, [ft]: e.target.value })} />
            </label>
          ))}
          <div className="flex items-end"><Button type="submit" size="sm">{t.admin.save}</Button></div>
        </form>
      </CardContent>
    </Card>
  );
}

export function AdminBilling() {
  const { t } = useI18n();
  const b = t.admin.billing;
  const date = useDate();
  const qc = useQueryClient();
  const plans = useAdmin<{ data: AdminPlan[]; meta: { features: Record<string, string> } }>("/billing/plans");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const subs = useAdmin<Paged<SubRow>>("/billing/subscriptions", { status, page });
  const events = useAdmin<{ data: EventRow[] }>("/billing/events");

  async function revoke(id: string) {
    try {
      await api(`/admin/billing/subscriptions/${id}/revoke`, { method: "POST" });
      qc.invalidateQueries({ queryKey: ["admin"] });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  return (
    <div className="space-y-6">
      <AdminTitle>{t.admin.nav.billing}</AdminTitle>
      <section className="space-y-3">
        <h2 className="text-lg font-semibold">{b.plans}</h2>
        {plans.error ? <ErrorState error={plans.error} /> : !plans.data ? <Skeleton className="h-96" /> : plans.data.data.map((p) => (
          <PlanRow key={`${p.id}-${p.is_active}-${p.is_public}`} plan={p} features={Object.keys(plans.data.meta.features)} />
        ))}
      </section>
      <section className="space-y-3">
        <div className="flex items-center justify-between gap-2">
          <h2 className="text-lg font-semibold">{b.subscriptions}</h2>
          <Select className="w-auto" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} aria-label="status">
            <option value="">{t.admin.all}</option>
            {["active", "past_due", "canceled", "trialing"].map((s) => <option key={s} value={s}>{t.billing.statuses[s]}</option>)}
          </Select>
        </div>
        {subs.error ? <ErrorState error={subs.error} /> : !subs.data ? <Skeleton className="h-64" /> : subs.data.data.length === 0 ? <EmptyState title={t.admin.none} /> : (
          <Table>
            <thead><tr><th>{t.admin.users.email}</th><th>{t.admin.users.plan}</th><th>{t.admin.users.status}</th><th>{b.provider}</th><th /><th /></tr></thead>
            <tbody>{subs.data.data.map((s) => (
              <tr key={s.id}>
                <td>{s.user?.email}</td><td className="font-mono text-xs">{s.plan}</td>
                <td><Badge variant={s.status === "active" ? "success" : "muted"}>{t.billing.statuses[s.status] ?? s.status}</Badge>{s.cancel_at_period_end && s.provider !== "manual" && <Badge variant="warning" className="ms-1">↘</Badge>}</td>
                <td>{s.provider}{s.note && <span className="block text-xs text-muted-foreground">{s.note}</span>}</td>
                <td>{date(s.current_period_end)}</td>
                <td>{s.provider === "manual" && s.status === "active" && <Button size="sm" variant="outline" onClick={() => revoke(s.id)}>{b.revoke}</Button>}</td>
              </tr>
            ))}</tbody>
          </Table>
        )}
        <Pager meta={subs.data?.meta} onPage={setPage} />
      </section>
      <section className="space-y-3">
        <h2 className="text-lg font-semibold">{b.events}</h2>
        {events.error ? <ErrorState error={events.error} /> : !events.data ? <Skeleton className="h-40" /> : events.data.data.length === 0 ? <EmptyState title={t.admin.none} /> : (
          <Table>
            <thead><tr><th>{b.type}</th><th>{t.admin.users.status}</th><th>ID</th><th>{t.admin.audit.when}</th></tr></thead>
            <tbody>{events.data.data.map((e) => (
              <tr key={e.id}><td className="font-mono text-xs">{e.type}</td><td><Badge variant={e.status === "failed" ? "warning" : "muted"}>{e.status}</Badge>{e.error && <span className="block text-xs text-destructive">{e.error}</span>}</td><td className="font-mono text-xs">{e.event_id}</td><td>{date(e.created_at)}</td></tr>
            ))}</tbody>
          </Table>
        )}
      </section>
    </div>
  );
}
