"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { Input, Select } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import { AdminTitle, Pager, Table, useAdmin, type Paged } from "./admin-shell";

type Capability = { id: number; feature: string; provider: string; status: "supported" | "beta" | "unsupported"; quality_tier: number; notes: string | null };
type LanguageRow = { code: string; name: string; is_active: boolean; is_target_enabled: boolean; capabilities: Capability[] };
type AuditRow = { id: number; action: string; actor: { id: number; name: string; email: string } | null; subject: string | null; old: unknown; new: unknown; ip: string | null; created_at: string };

export function AdminLanguages() {
  const { t } = useI18n();
  const qc = useQueryClient();
  const list = useAdmin<{ data: LanguageRow[] }>("/languages");
  if (list.error) return <ErrorState error={list.error} onRetry={() => list.refetch()} />;
  if (!list.data) return <Skeleton className="h-96" />;
  const features = Array.from(new Set(list.data.data.flatMap((l) => l.capabilities.map((c) => c.feature)))).sort();

  async function update(id: number, status: string) {
    try {
      const res = await api(`/admin/language-capabilities/${id}`, { method: "PATCH", body: { status } });
      qc.setQueryData(["admin", "/languages"], res);
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  return (
    <div className="space-y-4">
      <AdminTitle description={t.admin.languages.help}>{t.admin.languages.title}</AdminTitle>
      <Table>
        <thead><tr><th />{features.map((f) => <th key={f} className="font-mono text-xs">{f}</th>)}</tr></thead>
        <tbody>{list.data.data.map((l) => (
          <tr key={l.code}>
            <td className="font-medium">{l.name} <span className="text-muted-foreground">({l.code})</span></td>
            {features.map((f) => {
              const c = l.capabilities.find((x) => x.feature === f);
              return (
                <td key={f}>
                  {c ? (
                    <Select className="h-8 w-auto text-xs" value={c.status} onChange={(e) => update(c.id, e.target.value)} aria-label={`${l.code} ${f}`}>
                      <option value="supported">supported</option><option value="beta">beta</option><option value="unsupported">unsupported</option>
                    </Select>
                  ) : "—"}
                </td>
              );
            })}
          </tr>
        ))}</tbody>
      </Table>
    </div>
  );
}

export function AdminAudit() {
  const { t } = useI18n();
  const a = t.admin.audit;
  const [q, setQ] = useState("");
  const [action, setAction] = useState("");
  const [page, setPage] = useState(1);
  const list = useAdmin<Paged<AuditRow>>("/audit-logs", { action, page });
  return (
    <div className="space-y-4">
      <AdminTitle>{t.admin.nav.audit}</AdminTitle>
      <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); setAction(q); setPage(1); }}>
        <Input className="max-w-xs" placeholder={a.filter} value={q} onChange={(e) => setQ(e.target.value)} aria-label={a.filter} dir="ltr" />
        <Button type="submit" variant="outline">{t.admin.search}</Button>
      </form>
      {list.error ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : !list.data ? <Skeleton className="h-96" /> : list.data.data.length === 0 ? <EmptyState title={t.admin.none} /> : (
        <Table>
          <thead><tr><th>{a.when}</th><th>{a.action}</th><th>{a.actor}</th><th>{a.subject}</th><th>{a.changes}</th></tr></thead>
          <tbody>{list.data.data.map((r) => (
            <tr key={r.id} className="align-top">
              <td className="whitespace-nowrap text-xs">{new Date(r.created_at).toLocaleString()}</td>
              <td className="font-mono text-xs">{r.action}</td>
              <td className="text-xs">{r.actor ? r.actor.email : "system"}</td>
              <td className="font-mono text-xs">{r.subject}</td>
              <td className="max-w-md text-xs"><code className="break-all text-muted-foreground" dir="ltr">{r.old ? `${JSON.stringify(r.old)} → ` : ""}{r.new ? JSON.stringify(r.new) : ""}</code></td>
            </tr>
          ))}</tbody>
        </Table>
      )}
      <Pager meta={list.data?.meta} onPage={setPage} />
    </div>
  );
}
