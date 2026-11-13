"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Switch } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { ErrorState } from "@/components/ui/states";
import { api } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import { AdminTitle, Table, useAdmin } from "./admin-shell";

type Model = {
  id: number; slug: string; operation: string; is_enabled: boolean;
  input_cost_per_mtok: number | null; output_cost_per_mtok: number | null; audio_cost_per_minute: number | null; cost_per_mchar: number | null;
  usage_7d: { calls: number; cost_micros: number; avg_latency_ms: number; errors: number } | null;
};
type Provider = { id: number; slug: string; name: string; driver: string; is_enabled: boolean; is_default: boolean; models: Model[] };
type Prompt = { key: string; description: string | null; versions: { id: number; version: number; status: string; template: string; json_schema: unknown; created_at: string | null }[] };
const PRICE_FIELDS = ["input_cost_per_mtok", "output_cost_per_mtok", "audio_cost_per_minute", "cost_per_mchar"] as const;

function ModelRow({ m }: { m: Model }) {
  const { t } = useI18n();
  const qc = useQueryClient();
  const [prices, setPrices] = useState<Record<string, string>>(() => Object.fromEntries(PRICE_FIELDS.map((k) => [k, m[k] == null ? "" : String(m[k])])));
  async function save(body: Record<string, unknown>) {
    try {
      const res = await api<{ data: Provider[] }>(`/admin/ai/models/${m.id}`, { method: "PATCH", body });
      qc.setQueryData(["admin", "/ai/providers"], res);
      toast.success(t.admin.saved);
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }
  return (
    <tr>
      <td><p className="font-mono text-xs">{m.slug}</p><p className="text-xs text-muted-foreground">{m.operation}</p></td>
      <td><Switch checked={m.is_enabled} onCheckedChange={(v) => save({ is_enabled: v })} aria-label={t.admin.ai.enabled} /></td>
      {PRICE_FIELDS.map((k) => (
        <td key={k}><Input className="h-8 w-24" inputMode="decimal" value={prices[k]} onChange={(e) => setPrices({ ...prices, [k]: e.target.value })} aria-label={k} /></td>
      ))}
      <td className="text-xs text-muted-foreground">{m.usage_7d ? `${m.usage_7d.calls} · $${(m.usage_7d.cost_micros / 1e6).toFixed(4)} · ${m.usage_7d.avg_latency_ms} ms · ${m.usage_7d.errors} err` : "—"}</td>
      <td><Button size="sm" variant="outline" onClick={() => save(Object.fromEntries(PRICE_FIELDS.map((k) => [k, prices[k] === "" ? null : Number(prices[k])])))}>{t.admin.save}</Button></td>
    </tr>
  );
}

function PromptCard({ p }: { p: Prompt }) {
  const { t, f } = useI18n();
  const a = t.admin.ai;
  const qc = useQueryClient();
  const active = p.versions.find((v) => v.status === "active");
  const [draft, setDraft] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function run(path: string, body?: unknown) {
    setBusy(true);
    try {
      await api(`/admin${path}`, { method: "POST", body });
      toast.success(t.admin.saved);
      setDraft(null);
      qc.invalidateQueries({ queryKey: ["admin", "/ai/prompts"] });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-2 space-y-0">
        <CardTitle className="font-mono text-sm">{p.key}</CardTitle>
        <div className="flex flex-wrap gap-1">
          {p.versions.map((v) => (
            <Badge key={v.id} variant={v.status === "active" ? "success" : v.status === "draft" ? "warning" : "muted"}>{f(a.version, { n: v.version })} · {a.statuses[v.status] ?? v.status}</Badge>
          ))}
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        {p.versions.filter((v) => v.status === "draft").map((v) => (
          <div key={v.id} className="flex items-center justify-between rounded-lg border border-warning/50 p-2 text-sm">
            <span>{f(a.version, { n: v.version })} · {a.statuses.draft}</span>
            <Button size="sm" onClick={() => run(`/ai/prompt-versions/${v.id}/activate`)} loading={busy}>{a.activate}</Button>
          </div>
        ))}
        {draft === null ? (
          <details>
            <summary className="cursor-pointer text-sm text-muted-foreground">{active ? f(a.version, { n: active.version }) : ""} · {a.statuses.active}</summary>
            <pre className="mt-2 max-h-72 overflow-auto whitespace-pre-wrap rounded-lg bg-muted/60 p-3 text-xs" dir="ltr">{active?.template}</pre>
            <Button size="sm" variant="outline" className="mt-2" onClick={() => setDraft(active?.template ?? "")}>{a.newVersion}</Button>
          </details>
        ) : (
          <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); run(`/ai/prompts/${p.key}/versions`, { template: draft }); }}>
            <textarea value={draft} onChange={(e) => setDraft(e.target.value)} rows={14} dir="ltr" aria-label={a.newVersion}
              className="w-full rounded-lg border border-input bg-card p-3 font-mono text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40" />
            <div className="flex gap-2">
              <Button type="submit" size="sm" loading={busy}>{a.createDraft}</Button>
              <Button type="button" size="sm" variant="ghost" onClick={() => setDraft(null)}>{t.common.cancel}</Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  );
}

export function AdminAi() {
  const { t } = useI18n();
  const a = t.admin.ai;
  const qc = useQueryClient();
  const providers = useAdmin<{ data: Provider[] }>("/ai/providers");
  const prompts = useAdmin<{ data: Prompt[] }>("/ai/prompts");

  async function toggleProvider(p: Provider, v: boolean) {
    try {
      const res = await api<{ data: Provider[] }>(`/admin/ai/providers/${p.id}`, { method: "PATCH", body: { is_enabled: v } });
      qc.setQueryData(["admin", "/ai/providers"], res);
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  return (
    <div className="space-y-6">
      <AdminTitle description={a.killSwitch}>{t.admin.nav.ai}</AdminTitle>
      <h2 className="text-lg font-semibold">{a.providers}</h2>
      {providers.error ? <ErrorState error={providers.error} /> : !providers.data ? <Skeleton className="h-64" /> : providers.data.data.map((p) => (
        <Card key={p.id}>
          <CardHeader className="flex-row items-center justify-between gap-2 space-y-0">
            <div>
              <CardTitle className="text-base">{p.name} {p.is_default && <Badge variant="outline">{a.defaultProvider}</Badge>}</CardTitle>
              <CardDescription>{p.slug} · {p.driver}</CardDescription>
            </div>
            <label className="flex items-center gap-2 text-sm">{a.enabled} <Switch checked={p.is_enabled} onCheckedChange={(v) => toggleProvider(p, v)} /></label>
          </CardHeader>
          <CardContent>
            <Table>
              <thead><tr><th>{a.model}</th><th>{a.enabled}</th><th>{a.input}</th><th>{a.output}</th><th>{a.audio}</th><th>{a.chars}</th><th>{a.usage}</th><th /></tr></thead>
              <tbody>{p.models.map((m) => <ModelRow key={`${m.id}-${m.is_enabled}`} m={m} />)}</tbody>
            </Table>
          </CardContent>
        </Card>
      ))}
      <section className="space-y-3">
        <h2 className="text-lg font-semibold">{a.prompts}</h2>
        {prompts.error ? <ErrorState error={prompts.error} /> : !prompts.data ? <Skeleton className="h-64" /> : prompts.data.data.map((p) => <PromptCard key={p.key} p={p} />)}
      </section>
    </div>
  );
}
