"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { Check, CheckCircle2, CreditCard, ExternalLink, Loader2, Minus, Users } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { useBilling, usePayments, usePlans } from "@/features/queries";
import { api } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import type { BillingPlan, BillingState, QuotaFeature, UsageRow } from "@/lib/types";
import { cn } from "@/lib/utils";

const FEATURE_ORDER: QuotaFeature[] = ["tutor_messages", "realtime_minutes", "writing_evaluations", "pronunciation_checks", "placement_productive", "target_languages"];

export function useMoney() {
  const { locale } = useI18n();
  return (minor: number, currency = "USD") =>
    new Intl.NumberFormat(locale === "ar" ? "ar-EG" : "en-US", { style: "currency", currency, maximumFractionDigits: minor % 100 === 0 ? 0 : 2 }).format(minor / 100);
}

export function useDate() {
  const { locale } = useI18n();
  return (iso: string | null | undefined) => (iso ? new Intl.DateTimeFormat(locale === "ar" ? "ar-EG" : "en-GB", { dateStyle: "medium" }).format(new Date(iso)) : "");
}

function planName(t: ReturnType<typeof useI18n>["t"], plan: Pick<BillingPlan, "tier">) {
  return t.billing.tiers[plan.tier] ?? plan.tier;
}

function FeatureLine({ feature, limit }: { feature: QuotaFeature; limit: number | null | undefined }) {
  const { t, f } = useI18n();
  if (limit === 0) {
    return (
      <li className="flex items-start gap-2 text-muted-foreground">
        <Minus className="mt-0.5 size-4 shrink-0" aria-hidden /> {feature === "realtime_minutes" ? t.billing.notIncluded : f(t.billing.features[feature], { n: 0 })}
      </li>
    );
  }
  return (
    <li className="flex items-start gap-2">
      <Check className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
      {limit === null ? t.billing.featuresUnlimited[feature] : limit === 1 && t.billing.features[`${feature}_one`] ? t.billing.features[`${feature}_one`] : f(t.billing.features[feature], { n: limit ?? 0 })}
    </li>
  );
}

export function PricingView() {
  const { t, f } = useI18n();
  const plans = usePlans();
  const billing = useBilling();
  const money = useMoney();
  const searchParams = useSearchParams();
  const [interval, setInterval] = useState<"month" | "year">("year");
  const [busy, setBusy] = useState<string | null>(null);

  if (plans.error) return <ErrorState error={plans.error} onRetry={() => plans.refetch()} />;
  if (!plans.data || !billing.data) return <Skeleton className="h-[32rem]" />;

  const all = plans.data.data;
  const shown = all.filter((p) => p.tier === "free" || p.interval === interval);
  const current = billing.data.plan.slug;
  const subscribed = billing.data.source !== "free";
  const testMode = billing.data.driver === "fake";

  async function choose(plan: BillingPlan) {
    setBusy(plan.slug);
    try {
      const { data } = await api<{ data: { url: string } }>("/billing/checkout", { method: "POST", body: { plan: plan.slug } });
      window.location.assign(data.url); // provider-hosted checkout (or the local test return page)
    } catch (e) {
      toastError(e, t.common.errorTitle);
      setBusy(null);
    }
  }

  return (
    <div className="space-y-8">
      <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <h1 className="font-display text-3xl md:text-4xl">{t.billing.title}</h1>
          <p className="mt-1 max-w-xl text-muted-foreground">{t.billing.subtitle}</p>
        </div>
        <div role="radiogroup" aria-label={t.billing.title} className="inline-flex rounded-lg border bg-card p-1 text-sm">
          {(["month", "year"] as const).map((i) => (
            <button
              key={i}
              role="radio"
              aria-checked={interval === i}
              onClick={() => setInterval(i)}
              className={cn("rounded-md px-3 py-1.5 font-medium transition-colors", interval === i ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground")}
            >
              {i === "month" ? t.billing.monthly : t.billing.yearly}
              {i === "year" && <span className="ms-1.5 text-xs opacity-80">· {t.billing.yearlyHint}</span>}
            </button>
          ))}
        </div>
      </div>

      {searchParams.get("canceled") && <p role="status" className="rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground">{t.billing.canceled}</p>}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {shown.map((plan) => {
          const isCurrent = plan.slug === current || (plan.tier === "free" && !subscribed);
          const highlight = plan.tier === "premium";
          return (
            <Card key={plan.slug} className={cn("flex flex-col", highlight && "border-primary shadow-md", isCurrent && "ring-2 ring-primary/40")}>
              <CardHeader className="space-y-2">
                <div className="flex items-center justify-between gap-2">
                  <CardTitle className="text-lg">{planName(t, plan)}</CardTitle>
                  {isCurrent && <Badge variant="success">{t.billing.current}</Badge>}
                  {plan.seats > 1 && !isCurrent && <Badge variant="outline"><Users className="size-3" /> {f(t.billing.seats, { n: plan.seats })}</Badge>}
                </div>
                <p>
                  <span className="font-display text-4xl">{money(plan.price_minor, plan.currency)}</span>
                  {plan.interval && <span className="ms-1 text-sm text-muted-foreground">{plan.interval === "year" ? t.billing.perYear : t.billing.perMonth}</span>}
                </p>
                {plan.interval === "year" && <p className="text-xs text-muted-foreground">≈ {money(Math.round(plan.price_minor / 12), plan.currency)} {t.billing.perMonth}</p>}
                <CardDescription>{t.billing.blurbs[plan.tier]}</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-1 flex-col gap-5">
                <ul className="space-y-2 text-sm">
                  {FEATURE_ORDER.filter((ft) => ft !== "placement_productive").map((ft) => <FeatureLine key={ft} feature={ft} limit={plan.limits[ft]} />)}
                </ul>
                <div className="mt-auto">
                  {plan.tier === "free" ? null : isCurrent ? (
                    <Button asChild variant="outline" className="w-full"><Link href="/billing">{t.billing.manage}</Link></Button>
                  ) : subscribed && plan.seats === 1 ? (
                    <Button asChild variant="outline" className="w-full"><Link href="/billing">{t.billing.change}</Link></Button>
                  ) : (
                    <Button
                      className="w-full"
                      variant={highlight ? "default" : "outline"}
                      loading={busy === plan.slug}
                      disabled={!plans.data.meta.checkout_available || busy !== null}
                      onClick={() => choose(plan)}
                    >
                      {f(t.billing.choose, { plan: planName(t, plan) })}
                    </Button>
                  )}
                </div>
              </CardContent>
            </Card>
          );
        })}
      </div>

      <div className="space-y-1 text-sm text-muted-foreground">
        <p>{t.billing.alwaysFree}</p>
        <p>{t.billing.legal}</p>
        {testMode && <p className="font-medium text-foreground">{t.billing.testMode}</p>}
        {!plans.data.meta.checkout_available && <p>{t.billing.checkoutUnavailable}</p>}
      </div>
    </div>
  );
}

export function UsageMeters({ usage, compact = false }: { usage: UsageRow[]; compact?: boolean }) {
  const { t, f } = useI18n();
  const date = useDate();
  const rows = FEATURE_ORDER.map((ft) => usage.find((u) => u.feature === ft)).filter((u): u is UsageRow => !!u && u.limit !== 0);
  return (
    <ul className={cn("grid gap-4", !compact && "sm:grid-cols-2")}>
      {rows.map((u) => {
        const pct = u.limit ? Math.min(100, (u.used / u.limit) * 100) : 0;
        return (
          <li key={u.feature} className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-2 text-sm">
              <span className="font-medium">{t.billing.featureNames[u.feature]}</span>
              <span className="tabular-nums text-muted-foreground">
                {u.limit === null ? t.billing.unlimited : f(t.billing.used, { used: u.used, limit: u.limit })}
              </span>
            </div>
            {u.limit !== null && <Progress value={pct} indicatorClassName={pct >= 100 ? "bg-destructive" : pct >= 80 ? "bg-warning" : undefined} />}
            {u.resets_at && u.limit !== null && (
              <p className="text-xs text-muted-foreground">
                {u.period === "day" ? t.billing.perDay : t.billing.perMonthUsage} · {f(t.billing.resets, { date: date(u.resets_at) })}
              </p>
            )}
          </li>
        );
      })}
    </ul>
  );
}

export function BillingView() {
  const { t, f } = useI18n();
  const billing = useBilling();
  const payments = usePayments();
  const qc = useQueryClient();
  const money = useMoney();
  const date = useDate();
  const [busy, setBusy] = useState(false);

  if (billing.error) return <ErrorState error={billing.error} onRetry={() => billing.refetch()} />;
  if (!billing.data) return <Skeleton className="h-96" />;
  const b = billing.data;
  const sub = b.subscription;

  async function act(path: string, confirmText?: string) {
    if (confirmText && !window.confirm(confirmText)) return;
    setBusy(true);
    try {
      if (path === "/billing/portal") {
        const { data } = await api<{ data: { url: string } }>(path, { method: "POST" });
        window.location.assign(data.url);
        return;
      }
      const { data } = await api<{ data: BillingState }>(path, { method: "POST" });
      qc.setQueryData(["billing", "subscription"], data);
      qc.invalidateQueries({ queryKey: ["notifications"] });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-display text-3xl md:text-4xl">{t.billing.manage}</h1>
      </div>

      <Card>
        <CardHeader className="flex-row items-start justify-between gap-4 space-y-0">
          <div className="space-y-1">
            <CardDescription>{t.billing.yourPlan}</CardDescription>
            <CardTitle className="font-display text-2xl">{planName(t, b.plan)}</CardTitle>
            {sub?.organization && b.source === "organization" && <p className="text-sm text-muted-foreground">{f(t.billing.via, { group: sub.organization.name })}</p>}
          </div>
          {sub && <Badge variant={sub.status === "active" ? "success" : sub.status === "past_due" ? "warning" : "muted"}>{t.billing.statuses[sub.status] ?? sub.status}</Badge>}
        </CardHeader>
        <CardContent className="space-y-4">
          {sub?.status === "past_due" && <p role="alert" className="rounded-lg bg-warning/15 p-3 text-sm">{t.billing.pastDue}</p>}
          {sub?.current_period_end && (
            <p className="text-sm text-muted-foreground">
              {f(sub.cancel_at_period_end ? t.billing.endsOn : t.billing.renewsOn, { date: date(sub.current_period_end) })}
            </p>
          )}
          {sub && !sub.can_manage && <p className="text-sm text-muted-foreground">{t.billing.managedByOwner}</p>}
          <div className="flex flex-wrap gap-2">
            {b.source === "free" && <Button asChild><Link href="/pricing">{t.billing.upgrade}</Link></Button>}
            {sub?.can_manage && (
              sub.cancel_at_period_end ? (
                <Button onClick={() => act("/billing/subscription/resume")} loading={busy}>{t.billing.resume}</Button>
              ) : (
                <Button variant="outline" onClick={() => act("/billing/subscription/cancel", f(t.billing.cancelConfirm, { date: date(sub.current_period_end) }))} loading={busy}>
                  {t.billing.cancel}
                </Button>
              )
            )}
            {b.portal_available && (
              <Button variant="outline" onClick={() => act("/billing/portal")} disabled={busy}>
                <CreditCard /> {t.billing.portal} <ExternalLink className="size-3.5" />
              </Button>
            )}
            {sub?.organization && <Button asChild variant="ghost"><Link href="/family"><Users /> {t.billing.familyCta}</Link></Button>}
            {b.source !== "free" && <Button asChild variant="ghost"><Link href="/pricing">{t.billing.seePlans}</Link></Button>}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{t.billing.usage}</CardTitle></CardHeader>
        <CardContent><UsageMeters usage={b.usage} /></CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{t.billing.payments}</CardTitle></CardHeader>
        <CardContent>
          {payments.error ? <ErrorState error={payments.error} onRetry={() => payments.refetch()} /> : !payments.data ? <Skeleton className="h-20" /> : payments.data.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t.billing.noPayments}</p>
          ) : (
            <ul className="divide-y text-sm">
              {payments.data.map((p) => (
                <li key={p.id} className="flex items-center justify-between gap-3 py-2.5">
                  <span>{date(p.date)}</span>
                  <span className="tabular-nums">{money(p.amount_minor, p.currency)}</span>
                  <Badge variant={p.status === "paid" ? "success" : "warning"}>{t.billing.paymentStatus[p.status]}</Badge>
                  {p.invoice_url ? <a href={p.invoice_url} target="_blank" rel="noreferrer" className="text-primary hover:underline">{t.billing.invoice}</a> : <span />}
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

/** Return page after checkout: confirms test-mode sessions, otherwise waits for the provider's webhook. */
export function BillingReturnView() {
  const { t, f } = useI18n();
  const params = useSearchParams();
  const sessionId = params.get("session_id");
  const qc = useQueryClient();
  const billing = useBilling();
  const [phase, setPhase] = useState<"pending" | "done" | "slow" | "error">("pending");
  const [error, setError] = useState<unknown>(null);
  const started = useRef(false);
  const deadline = useRef(0);

  useEffect(() => {
    if (started.current || !sessionId) return;
    started.current = true;
    deadline.current = performance.now() + 30_000;
    api<{ data: { status: string } }>("/billing/checkout/confirm", { method: "POST", body: { session_id: sessionId } })
      .then(() => qc.invalidateQueries({ queryKey: ["billing"] }))
      .catch((e) => { setError(e); setPhase("error"); });
  }, [sessionId, qc, deadline]);

  const live = billing.data && billing.data.source !== "free";
  useEffect(() => {
    if (phase !== "pending") return;
    if (live) {
      qc.invalidateQueries({ queryKey: ["me"] });
      qc.invalidateQueries({ queryKey: ["notifications"] });
      const id = window.setTimeout(() => setPhase("done"), 0);
      return () => window.clearTimeout(id);
    }
    const id = window.setTimeout(() => {
      if (deadline.current && performance.now() > deadline.current) setPhase("slow");
      else billing.refetch();
    }, 2000);
    return () => window.clearTimeout(id);
  }, [live, phase, billing, qc, deadline]);

  if (!sessionId) return <EmptyState title={t.billing.returnSlow} action={<Button asChild><Link href="/billing">{t.billing.manage}</Link></Button>} />;
  if (phase === "error") return <ErrorState error={error} />;

  return (
    <Card className="mx-auto max-w-lg">
      <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
        {phase === "done" ? <CheckCircle2 className="size-10 text-success" aria-hidden /> : <Loader2 className="size-10 animate-spin text-primary" aria-hidden />}
        <h1 className="font-display text-2xl" aria-live="polite">
          {phase === "done" && billing.data ? f(t.billing.returnDone, { plan: planName(t, billing.data.plan) }) : t.billing.returnTitle}
        </h1>
        {phase === "pending" && <p className="text-sm text-muted-foreground">{t.billing.returnPending}</p>}
        {phase === "slow" && <p className="text-sm text-muted-foreground">{t.billing.returnSlow}</p>}
        {phase !== "pending" && (
          <div className="flex flex-wrap justify-center gap-2">
            <Button asChild><Link href="/home">{t.billing.startLearning}</Link></Button>
            {billing.data?.subscription?.organization && <Button asChild variant="outline"><Link href="/family">{t.billing.familyCta}</Link></Button>}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
