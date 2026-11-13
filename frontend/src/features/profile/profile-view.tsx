"use client";

import Link from "next/link";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useTheme } from "next-themes";
import { Download, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Switch } from "@/components/ui/checkbox";
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Input, Select } from "@/components/ui/input";
import { FieldError, Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { keys, useCatalog, useMe } from "@/features/queries";
import { api, ApiError } from "@/lib/api";
import { dictionaries, useI18n, type Locale } from "@/lib/i18n";
import type { User } from "@/lib/types";
import { cn } from "@/lib/utils";
import { useUiStore } from "@/stores/ui-store";
import { toastError } from "@/lib/errors";

type ExportRow = { id: string; status: string; requested_at: string; ready_at: string | null; expires_at: string | null };

/** Async callbacks read the current dictionary directly (not a hook). */
const currentDictionary = () => dictionaries[useUiStore.getState().locale];

function useUpdateMe() {
  const qc = useQueryClient();
  return async (path: "/me/profile" | "/me/preferences", body: Record<string, unknown>) => {
    try {
      const { data } = await api<{ data: User }>(path, { method: "PATCH", body });
      qc.setQueryData(keys.me, data);
      toast.success(currentDictionary().common.saved);
      return data;
    } catch (e) {
      toast.error(e instanceof Error ? e.message : currentDictionary().common.errorTitle);
    }
  };
}

function AccountCard({ user }: { user: User }) {
  const { t } = useI18n();
  const catalog = useCatalog();
  const setLocale = useUiStore((s) => s.setLocale);
  const update = useUpdateMe();
  const [values, setValues] = useState({
    name: user.name,
    interface_language: user.profile.interface_language,
    native_language: user.profile.native_language ?? "",
    timezone: user.profile.timezone,
  });
  const [saving, setSaving] = useState(false);
  const timezones = typeof Intl.supportedValuesOf === "function" ? Intl.supportedValuesOf("timeZone") : [user.profile.timezone];

  async function save() {
    setSaving(true);
    const updated = await update("/me/profile", { ...values, native_language: values.native_language || null });
    if (updated) setLocale(updated.profile.interface_language as Locale);
    setSaving(false);
  }

  return (
    <Card>
      <CardHeader><CardTitle>{t.profile.account}</CardTitle><CardDescription>{user.email}</CardDescription></CardHeader>
      <CardContent className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2 sm:col-span-2">
          <Label htmlFor="name">{t.auth.name}</Label>
          <Input id="name" value={values.name} onChange={(e) => setValues({ ...values, name: e.target.value })} />
        </div>
        <div className="space-y-2">
          <Label htmlFor="ui-lang">{t.auth.interfaceLanguage}</Label>
          <Select id="ui-lang" value={values.interface_language} onChange={(e) => setValues({ ...values, interface_language: e.target.value as Locale })}>
            <option value="en">English</option>
            <option value="ar">العربية</option>
          </Select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="native">{t.auth.nativeLanguage}</Label>
          <Select id="native" value={values.native_language} onChange={(e) => setValues({ ...values, native_language: e.target.value })}>
            <option value="">—</option>
            {catalog.data?.languages.map((l) => <option key={l.code} value={l.code}>{l.native_name}</option>)}
          </Select>
        </div>
        <div className="space-y-2 sm:col-span-2">
          <Label htmlFor="tz">{t.profile.timezone}</Label>
          <Select id="tz" value={values.timezone} onChange={(e) => setValues({ ...values, timezone: e.target.value })}>
            {timezones.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
          </Select>
        </div>
        <Button className="sm:col-span-2" onClick={save} loading={saving}>{t.common.save}</Button>
      </CardContent>
    </Card>
  );
}

function PreferencesCard({ user }: { user: User }) {
  const { t } = useI18n();
  const { theme, setTheme } = useTheme();
  const update = useUpdateMe();
  const themes = ["light", "dark", "system"] as const;

  return (
    <Card>
      <CardHeader><CardTitle>{t.profile.appearance}</CardTitle></CardHeader>
      <CardContent className="space-y-6">
        <div role="radiogroup" className="grid grid-cols-3 gap-2">
          {themes.map((th) => (
            <button
              key={th}
              role="radio"
              aria-checked={(theme ?? "system") === th}
              onClick={() => { setTheme(th); update("/me/preferences", { theme: th }); }}
              className={cn("rounded-lg border py-2.5 text-sm", (theme ?? "system") === th ? "border-primary bg-secondary text-secondary-foreground" : "bg-card hover:bg-muted")}
            >
              {t.profile.theme[th]}
            </button>
          ))}
        </div>
        <div className="flex items-center justify-between gap-4">
          <Label htmlFor="reminder">{t.profile.reminders}</Label>
          <div className="flex items-center gap-3">
            <Input
              type="time"
              aria-label={t.profile.reminders}
              className="h-9 w-28"
              defaultValue={user.preferences.reminder_time?.slice(0, 5) ?? ""}
              disabled={!user.preferences.daily_reminder_enabled}
              onBlur={(e) => e.target.value && update("/me/preferences", { reminder_time: e.target.value })}
            />
            <Switch id="reminder" checked={user.preferences.daily_reminder_enabled} onCheckedChange={(v) => update("/me/preferences", { daily_reminder_enabled: v })} />
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function LanguagesCard({ user }: { user: User }) {
  const { t } = useI18n();
  const qc = useQueryClient();
  const setActive = useUiStore((s) => s.setActiveProfileId);
  const router = useRouter();

  async function archive(id: string) {
    try {
      await api(`/learner-profiles/${id}`, { method: "DELETE" });
      setActive(null);
      await qc.invalidateQueries({ queryKey: keys.me });
      if (user.learner_profiles.length <= 1) router.replace("/onboarding");
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  return (
    <Card>
      <CardHeader><CardTitle>{t.profile.languages}</CardTitle></CardHeader>
      <CardContent>
        <ul className="divide-y">
          {user.learner_profiles.map((p) => (
            <li key={p.id} className="flex items-center justify-between gap-3 py-3">
              <div>
                <p className="font-medium" lang={p.target_language.code}>{p.target_language.native_name}</p>
                <p className="text-xs text-muted-foreground">{t.purposes[p.purpose.slug as keyof typeof t.purposes]} · {p.daily_minutes} {t.common.minutes}</p>
              </div>
              <Dialog>
                <DialogTrigger asChild><Button variant="ghost" size="sm"><Trash2 /> {t.profile.archive}</Button></DialogTrigger>
                <DialogContent>
                  <DialogTitle>{p.target_language.native_name}</DialogTitle>
                  <DialogDescription>{t.profile.archiveConfirm}</DialogDescription>
                  <div className="mt-6 flex justify-end gap-2">
                    <DialogClose asChild><Button variant="outline">{t.common.cancel}</Button></DialogClose>
                    <DialogClose asChild><Button variant="destructive" onClick={() => archive(p.id)}>{t.profile.archive}</Button></DialogClose>
                  </div>
                </DialogContent>
              </Dialog>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function PrivacyCard() {
  const { t } = useI18n();
  const exports = useQuery({ queryKey: keys.exports, queryFn: () => api<{ data: ExportRow[] }>("/me/data-exports").then((r) => r.data) });
  const [requesting, setRequesting] = useState(false);

  async function request() {
    setRequesting(true);
    try {
      await api("/me/data-exports", { method: "POST" });
      toast.success(t.profile.exportRequested);
      exports.refetch();
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setRequesting(false);
    }
  }

  async function download(id: string) {
    try {
      const { url } = await api<{ url: string }>(`/me/data-exports/${id}/download`);
      window.location.assign(url);
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  const ready = exports.data?.find((e) => e.status === "ready" && e.expires_at && new Date(e.expires_at) > new Date());

  return (
    <Card>
      <CardHeader><CardTitle>{t.profile.privacy}</CardTitle><CardDescription>{t.profile.exportBody}</CardDescription></CardHeader>
      <CardContent className="flex flex-wrap gap-2">
        <Button variant="outline" onClick={request} loading={requesting}>{t.profile.export}</Button>
        {ready && <Button variant="secondary" onClick={() => download(ready.id)}><Download /> {t.profile.download}</Button>}
      </CardContent>
    </Card>
  );
}

function DangerCard() {
  const { t } = useI18n();
  const qc = useQueryClient();
  const router = useRouter();
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function destroy() {
    setBusy(true);
    setError(null);
    try {
      await api("/me", { method: "DELETE", body: { password } });
      qc.clear();
      router.replace("/");
    } catch (e) {
      setError(e instanceof ApiError ? e.errors?.password?.[0] ?? e.message : t.common.errorTitle);
      setBusy(false);
    }
  }

  return (
    <Card className="border-destructive/40">
      <CardHeader><CardTitle className="text-destructive">{t.profile.danger}</CardTitle><CardDescription>{t.profile.dangerBody}</CardDescription></CardHeader>
      <CardContent>
        <Dialog>
          <DialogTrigger asChild><Button variant="destructive">{t.profile.deleteButton}</Button></DialogTrigger>
          <DialogContent>
            <DialogTitle>{t.profile.danger}</DialogTitle>
            <DialogDescription>{t.profile.dangerBody}</DialogDescription>
            <div className="mt-4 space-y-2">
              <Label htmlFor="confirm-password">{t.profile.deleteConfirm}</Label>
              <Input id="confirm-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
              <FieldError message={error ?? undefined} />
            </div>
            <div className="mt-6 flex justify-end gap-2">
              <DialogClose asChild><Button variant="outline">{t.common.cancel}</Button></DialogClose>
              <Button variant="destructive" onClick={destroy} loading={busy} disabled={!password}>{t.profile.deleteButton}</Button>
            </div>
          </DialogContent>
        </Dialog>
      </CardContent>
    </Card>
  );
}

export function ProfileView() {
  const { t } = useI18n();
  const me = useMe();
  if (!me.data) return <Skeleton className="h-96" />;
  return (
    <div className="space-y-6">
      <h1 className="font-display text-3xl md:text-4xl">{t.profile.title}</h1>
      {/* Phones have no sidebar: plan, family, rewards and admin are reachable from here. */}
      <nav className="flex flex-wrap gap-2" aria-label={t.nav.profile}>
        {me.data.features.includes("billing") && <Button asChild variant="outline" size="sm"><Link href="/billing">{t.nav.plan}</Link></Button>}
        {me.data.features.includes("family") && <Button asChild variant="outline" size="sm"><Link href="/family">{t.family.title}</Link></Button>}
        {me.data.features.includes("rewards") && <Button asChild variant="outline" size="sm"><Link href="/rewards">{t.nav.rewards}</Link></Button>}
        {me.data.is_staff && <Button asChild variant="outline" size="sm"><Link href="/admin">{t.nav.admin}</Link></Button>}
      </nav>
      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-6">
          <AccountCard key={me.data.profile.interface_language + me.data.profile.timezone} user={me.data} />
          <LanguagesCard user={me.data} />
        </div>
        <div className="space-y-6">
          <PreferencesCard user={me.data} />
          <PrivacyCard />
          <DangerCard />
        </div>
      </div>
    </div>
  );
}
