"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { Input, Select } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { useDate } from "@/features/billing/billing-views";
import { useMe, usePlans } from "@/features/queries";
import { api } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import { AdminTitle, Pager, Table, useAdmin, type Paged } from "./admin-shell";

type UserRow = { id: number; name: string; email: string; status: "active" | "suspended"; roles: string[]; created_at: string; email_verified: boolean };
type SubRow = { id: string; plan: string; status: string; provider: string; current_period_end: string | null; cancel_at_period_end: boolean; note: string | null; created_at: string; user: { id: number; name: string; email: string } | null };
type UserDetail = UserRow & { timezone: string | null; languages: { code: string; status: string }[]; plan: { slug: string; source: string }; subscriptions: SubRow[]; available_roles: string[] };

export function AdminUsers() {
  const { t } = useI18n();
  const u = t.admin.users;
  const date = useDate();
  const [q, setQ] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<number | null>(null);
  const list = useAdmin<Paged<UserRow>>("/users", { q: search, status, page });

  return (
    <div className="space-y-4">
      <AdminTitle>{t.admin.nav.users}</AdminTitle>
      <form className="flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); setSearch(q); setPage(1); }}>
        <Input className="max-w-xs" placeholder={`${u.name} / ${u.email}`} value={q} onChange={(e) => setQ(e.target.value)} aria-label={t.admin.search} />
        <Select className="w-auto" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} aria-label={u.status}>
          <option value="">{t.admin.all}</option>
          <option value="active">{u.statuses.active}</option>
          <option value="suspended">{u.statuses.suspended}</option>
        </Select>
        <Button type="submit" variant="outline">{t.admin.search}</Button>
      </form>
      {list.error ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : !list.data ? <Skeleton className="h-96" /> : list.data.data.length === 0 ? <EmptyState title={t.admin.none} /> : (
        <Table>
          <thead><tr><th>{u.name}</th><th>{u.email}</th><th>{u.roles}</th><th>{u.status}</th><th>{u.joined}</th></tr></thead>
          <tbody>
            {list.data.data.map((r) => (
              <tr key={r.id} className="cursor-pointer hover:bg-muted/50" onClick={() => setOpen(r.id)}>
                <td><button type="button" className="font-medium hover:underline" onClick={() => setOpen(r.id)}>{r.name}</button></td>
                <td className="text-muted-foreground">{r.email}</td>
                <td className="space-x-1">{r.roles.map((role) => <Badge key={role} variant="outline">{role}</Badge>)}</td>
                <td><Badge variant={r.status === "active" ? "success" : "warning"}>{u.statuses[r.status]}</Badge></td>
                <td>{date(r.created_at)}</td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      <Pager meta={list.data?.meta} onPage={setPage} />
      <Dialog open={open !== null} onOpenChange={(v) => !v && setOpen(null)}>
        <DialogContent className="max-h-[90dvh] max-w-2xl overflow-y-auto">
          {open !== null && <UserPanel id={open} />}
        </DialogContent>
      </Dialog>
    </div>
  );
}

function UserPanel({ id }: { id: number }) {
  const { t } = useI18n();
  const u = t.admin.users;
  const me = useMe();
  const date = useDate();
  const qc = useQueryClient();
  const detail = useAdmin<{ data: UserDetail }>(`/users/${id}`);
  const plans = usePlans();
  const [roles, setRoles] = useState<string[] | null>(null);
  const [grant, setGrant] = useState({ plan: "premium_monthly", until: "", note: "" });
  const [busy, setBusy] = useState(false);

  if (detail.error) return <ErrorState error={detail.error} />;
  if (!detail.data) return <Skeleton className="h-64" />;
  const d = detail.data.data;
  const chosen = roles ?? d.roles;
  const canGrant = me.data?.permissions.includes("billing.manage");

  async function call(path: string, method: "PATCH" | "PUT" | "POST", body: unknown) {
    setBusy(true);
    try {
      await api(`/admin${path}`, { method, body });
      toast.success(t.admin.saved);
      qc.invalidateQueries({ queryKey: ["admin"] });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-5">
      <div>
        <DialogTitle>{d.name}</DialogTitle>
        <DialogDescription>{d.email} · {d.timezone} · {u.plan}: {d.plan.slug} ({d.plan.source})</DialogDescription>
      </div>
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className="text-muted-foreground">{u.languages}:</span>
        {d.languages.map((l) => <Badge key={l.code} variant="muted">{l.code} · {l.status}</Badge>)}
      </div>

      <section className="space-y-2">
        <h3 className="text-sm font-semibold">{u.roles}</h3>
        <div className="flex flex-wrap gap-3">
          {d.available_roles.map((r) => (
            <label key={r} className="flex items-center gap-2 text-sm">
              <Checkbox checked={chosen.includes(r)} onCheckedChange={(v) => setRoles(v ? [...chosen, r] : chosen.filter((x) => x !== r))} /> {r}
            </label>
          ))}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button size="sm" onClick={() => call(`/users/${id}/roles`, "PUT", { roles: chosen })} loading={busy}>{u.saveRoles}</Button>
          <Button size="sm" variant={d.status === "active" ? "destructive" : "outline"} disabled={busy}
            onClick={() => call(`/users/${id}/status`, "PATCH", { status: d.status === "active" ? "suspended" : "active" })}>
            {d.status === "active" ? u.suspend : u.reactivate}
          </Button>
        </div>
      </section>

      {canGrant && (
        <section className="space-y-2 border-t pt-4">
          <h3 className="text-sm font-semibold">{u.grant}</h3>
          <form className="grid gap-2 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); call(`/users/${id}/grants`, "POST", grant); }}>
            <Select value={grant.plan} onChange={(e) => setGrant({ ...grant, plan: e.target.value })} aria-label={u.plan}>
              {(plans.data?.data ?? []).filter((p) => p.tier !== "free" && p.seats === 1).map((p) => <option key={p.slug} value={p.slug}>{p.slug}</option>)}
            </Select>
            <div><Label className="sr-only" htmlFor="grant-until">{u.until}</Label><Input id="grant-until" type="date" required value={grant.until} onChange={(e) => setGrant({ ...grant, until: e.target.value })} /></div>
            <Input required minLength={3} placeholder={u.grantNote} value={grant.note} onChange={(e) => setGrant({ ...grant, note: e.target.value })} aria-label={u.grantNote} />
            <Button type="submit" size="sm" className="sm:col-span-3 sm:justify-self-start" loading={busy}>{u.grantButton}</Button>
          </form>
        </section>
      )}

      <section className="space-y-2 border-t pt-4">
        <h3 className="text-sm font-semibold">{u.subscriptions}</h3>
        {d.subscriptions.length === 0 ? <p className="text-sm text-muted-foreground">{t.admin.none}</p> : (
          <ul className="space-y-1 text-sm">
            {d.subscriptions.map((s) => (
              <li key={s.id} className="flex flex-wrap gap-2">
                <Badge variant="outline">{s.plan}</Badge><span>{s.status}</span><span className="text-muted-foreground">{s.provider}</span>
                {s.current_period_end && <span className="text-muted-foreground">→ {date(s.current_period_end)}</span>}
                {s.note && <span className="text-muted-foreground">“{s.note}”</span>}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
