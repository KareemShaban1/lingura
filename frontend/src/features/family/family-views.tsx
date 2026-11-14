"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Copy, MailPlus, UserMinus, Users } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { useDate } from "@/features/billing/billing-views";
import { useOrganization, useOrganizations } from "@/features/queries";
import { api, ApiError } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import type { InvitationPreview, OrganizationDetail } from "@/lib/types";

export function FamilyView() {
  const { t } = useI18n();
  const orgs = useOrganizations();
  const qc = useQueryClient();
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);

  if (orgs.error) return <ErrorState error={orgs.error} onRetry={() => orgs.refetch()} />;
  if (!orgs.data) return <Skeleton className="h-80" />;
  const group = orgs.data[0];

  async function create(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      await api("/organizations", { method: "POST", body: { name } });
      qc.invalidateQueries({ queryKey: ["organizations"] });
    } catch (err) {
      toastError(err, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-display text-3xl md:text-4xl">{t.family.title}</h1>
        <p className="mt-1 max-w-2xl text-muted-foreground">{t.family.subtitle}</p>
      </div>
      {group ? <GroupDetail id={group.id} /> : (
        <Card>
          <CardContent className="space-y-4 p-6">
            <p className="text-sm text-muted-foreground">{t.family.noPlan}</p>
            <div className="flex flex-wrap gap-2">
              <Button asChild><Link href="/pricing">{t.billing.seePlans}</Link></Button>
            </div>
            <form onSubmit={create} className="flex max-w-md flex-col gap-2 border-t pt-4 sm:flex-row sm:items-end">
              <div className="flex-1 space-y-1.5">
                <Label htmlFor="group-name">{t.family.name}</Label>
                <Input id="group-name" value={name} onChange={(e) => setName(e.target.value)} minLength={2} maxLength={120} required />
              </div>
              <Button type="submit" variant="outline" loading={busy}>{t.family.create}</Button>
            </form>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function GroupDetail({ id }: { id: string }) {
  const { t, f } = useI18n();
  const group = useOrganization(id);
  const qc = useQueryClient();
  const router = useRouter();
  const date = useDate();
  const [email, setEmail] = useState("");
  const [link, setLink] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (group.error) return <ErrorState error={group.error} onRetry={() => group.refetch()} />;
  if (!group.data) return <Skeleton className="h-80" />;
  const g = group.data;
  const isAdmin = g.role === "owner" || g.role === "admin";
  const update = (data: OrganizationDetail) => qc.setQueryData(["organization", id], data);

  async function invite(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const { data } = await api<{ data: { email: string; accept_url: string } }>(`/organizations/${id}/invitations`, { method: "POST", body: { email } });
      setLink(data.accept_url);
      toast.success(f(t.family.inviteSent, { email: data.email }));
      setEmail("");
      group.refetch();
    } catch (err) {
      toastError(err, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  async function run(path: string, method: "DELETE" | "POST", confirmText?: string) {
    if (confirmText && !window.confirm(confirmText)) return;
    try {
      const res = await api<{ data: OrganizationDetail | { left: boolean } | { revoked: boolean } }>(path, { method });
      if ("left" in res.data) {
        qc.invalidateQueries();
        router.push("/billing");
        return;
      }
      if ("members" in res.data) update(res.data);
      else group.refetch();
    } catch (err) {
      toastError(err, t.common.errorTitle);
    }
  }

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
      <Card>
        <CardHeader className="flex-row items-start justify-between gap-4 space-y-0">
          <div className="space-y-1">
            <CardTitle className="flex items-center gap-2"><Users className="size-5 text-primary" /> {g.name}</CardTitle>
            <CardDescription>{g.plan ? (t.billing.tiers.family) : t.family.noPlan}</CardDescription>
          </div>
          <Badge variant="outline">{t.family.roles[g.role]}</Badge>
        </CardHeader>
        <CardContent className="space-y-5">
          <div className="space-y-1.5">
            <p className="text-sm text-muted-foreground">{f(t.family.seatsUsed, { used: g.seats.used, seats: g.seats.seats })}</p>
            <Progress value={(g.seats.used / Math.max(1, g.seats.seats)) * 100} />
          </div>
          <div>
            <h2 className="mb-2 text-sm font-semibold">{t.family.members}</h2>
            <ul className="divide-y rounded-lg border">
              {g.members.map((m) => (
                <li key={m.id} className="flex items-center gap-3 px-3 py-2.5 text-sm">
                  <span className="grid size-8 place-items-center rounded-full bg-secondary font-medium text-secondary-foreground">{m.name.slice(0, 1)}</span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{m.name} {m.is_you && <span className="text-muted-foreground">({t.family.you})</span>}</p>
                    {m.email && <p className="truncate text-xs text-muted-foreground">{m.email}</p>}
                  </div>
                  <Badge variant="muted">{t.family.roles[m.role] ?? m.role}</Badge>
                  {isAdmin && m.role !== "owner" && (
                    <Button variant="ghost" size="icon" aria-label={t.family.remove}
                      onClick={() => run(`/organizations/${id}/members/${m.id}`, "DELETE", f(t.family.removeConfirm, { name: m.name }))}>
                      <UserMinus />
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          </div>
          {!isAdmin && <Button variant="outline" onClick={() => run(`/organizations/${id}/leave`, "POST", t.family.leaveConfirm)}>{t.family.leave}</Button>}
        </CardContent>
      </Card>

      {isAdmin && (
        <div className="space-y-6">
          <Card>
            <CardHeader><CardTitle className="flex items-center gap-2 text-base"><MailPlus className="size-4" /> {t.family.invite}</CardTitle></CardHeader>
            <CardContent className="space-y-3">
              {!g.plan ? (
                <><p className="text-sm text-muted-foreground">{t.family.noPlan}</p><Button asChild size="sm"><Link href="/pricing">{t.billing.seePlans}</Link></Button></>
              ) : (
                <form onSubmit={invite} className="space-y-2">
                  <Label htmlFor="invite-email">{t.family.inviteEmail}</Label>
                  <Input id="invite-email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="off" />
                  <Button type="submit" className="w-full" loading={busy} disabled={g.seats.used >= g.seats.seats}>{t.family.send}</Button>
                </form>
              )}
              {link && (
                <div className="space-y-2 rounded-lg bg-muted/60 p-3 text-xs">
                  <p className="text-muted-foreground">{t.family.linkHelp}</p>
                  <code className="block break-all">{link}</code>
                  <Button size="sm" variant="outline" onClick={() => navigator.clipboard.writeText(link).then(() => toast.success(t.family.copied))}>
                    <Copy /> {t.family.copy}
                  </Button>
                </div>
              )}
            </CardContent>
          </Card>
          {g.invitations.length > 0 && (
            <Card>
              <CardHeader><CardTitle className="text-base">{t.family.pending}</CardTitle></CardHeader>
              <CardContent>
                <ul className="space-y-2 text-sm">
                  {g.invitations.map((i) => (
                    <li key={i.id} className="flex items-center justify-between gap-2">
                      <div className="min-w-0">
                        <p className="truncate">{i.email}</p>
                        <p className="text-xs text-muted-foreground">{f(t.family.expires, { date: date(i.expires_at) })}</p>
                      </div>
                      <Button size="sm" variant="ghost" onClick={() => run(`/organization-invitations/${i.id}`, "DELETE")}>{t.family.revoke}</Button>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          )}
        </div>
      )}
    </div>
  );
}

export function InvitationView() {
  const { t, f } = useI18n();
  const { token } = useParams<{ token: string }>();
  const router = useRouter();
  const qc = useQueryClient();
  const [busy, setBusy] = useState(false);
  const preview = useQuery({
    queryKey: ["invitation", token],
    queryFn: () => api<{ data: InvitationPreview }>(`/invitations/${token}`).then((r) => r.data),
    retry: false,
  });

  if (preview.error) {
    const invalid = preview.error instanceof ApiError && preview.error.status === 404;
    return invalid ? <EmptyState title={t.family.invitation.expired} /> : <ErrorState error={preview.error} onRetry={() => preview.refetch()} />;
  }
  if (!preview.data) return <Skeleton className="h-56" />;
  const p = preview.data;

  async function accept() {
    setBusy(true);
    try {
      const { data } = await api<{ data: OrganizationDetail }>(`/invitations/${token}/accept`, { method: "POST" });
      toast.success(f(t.family.invitation.joined, { group: data.name }));
      await qc.invalidateQueries();
      router.replace("/family");
    } catch (e) {
      toastError(e, t.common.errorTitle);
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
        <span className="grid size-12 place-items-center rounded-full bg-secondary text-secondary-foreground"><Users className="size-6" /></span>
        <h1 className="font-display text-2xl">{t.family.invitation.title}</h1>
        <p className="text-muted-foreground">{f(t.family.invitation.body, { name: p.invited_by, group: p.organization })}</p>
        {p.status !== "pending" ? <p className="text-sm">{t.family.invitation.expired}</p>
          : !p.email_matches ? <p role="alert" className="rounded-lg bg-warning/15 p-3 text-sm">{t.family.invitation.wrongEmail}</p>
          : <Button size="lg" onClick={accept} loading={busy}>{t.family.invitation.accept}</Button>}
      </CardContent>
    </Card>
  );
}
