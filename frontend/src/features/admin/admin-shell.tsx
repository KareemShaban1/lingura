"use client";

import { Suspense, useEffect } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, BarChart3, Bot, ClipboardCheck, CreditCard, FileStack, Globe2, ScrollText, Users, type LucideIcon } from "lucide-react";
import { Logo } from "@/components/layout/app-shell";
import { Button } from "@/components/ui/button";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { FullPageLoader } from "@/features/auth-gate";
import { useRequireAuth } from "@/features/use-require-auth";
import { api, ApiError } from "@/lib/api";
import { setErrorNavigator } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import { cn } from "@/lib/utils";

/** Admin sections and the permission each needs (the API enforces the same rules on every route). */
export const ADMIN_SECTIONS: { href: string; key: string; permission: string; icon: LucideIcon }[] = [
  { href: "/admin", key: "overview", permission: "analytics.view", icon: BarChart3 },
  { href: "/admin/users", key: "users", permission: "users.manage", icon: Users },
  { href: "/admin/review", key: "review", permission: "content.review", icon: ClipboardCheck },
  { href: "/admin/content", key: "content", permission: "content.manage", icon: FileStack },
  { href: "/admin/ai", key: "ai", permission: "ai.manage", icon: Bot },
  { href: "/admin/billing", key: "billing", permission: "billing.manage", icon: CreditCard },
  { href: "/admin/languages", key: "languages", permission: "languages.manage", icon: Globe2 },
  { href: "/admin/audit", key: "audit", permission: "audit.view", icon: ScrollText },
];

function AdminNav({ permissions }: { permissions: string[] }) {
  const pathname = usePathname();
  const { t } = useI18n();
  return (
    <nav className="flex gap-1 overflow-x-auto md:flex-col" aria-label={t.admin.title}>
      {ADMIN_SECTIONS.filter((s) => permissions.includes(s.permission)).map(({ href, key, icon: Icon }) => {
        const active = href === "/admin" ? pathname === href : pathname.startsWith(href);
        return (
          <Link key={href} href={href} aria-current={active ? "page" : undefined}
            className={cn("flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground",
              active && "bg-secondary text-secondary-foreground")}>
            <Icon className="size-4" aria-hidden /> {t.admin.nav[key]}
          </Link>
        );
      })}
    </nav>
  );
}

export function AdminGate({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  const { data, error, refetch } = useRequireAuth({ requireProfile: false });
  const router = useRouter();
  const pathname = usePathname();
  useEffect(() => setErrorNavigator((href) => router.push(href)), [router]);

  if (!data && error && !(error instanceof ApiError && error.isUnauthenticated)) {
    return <div className="grid min-h-dvh place-items-center p-4"><ErrorState error={error} onRetry={() => refetch()} /></div>;
  }
  if (!data) return <FullPageLoader />;
  if (!data.is_staff) {
    return (
      <div className="grid min-h-dvh place-items-center p-4">
        <EmptyState title={t.admin.noAccess} action={<Button asChild><Link href="/home">{t.admin.backToApp}</Link></Button>} />
      </div>
    );
  }
  const section = ADMIN_SECTIONS.find((s) => (s.href === "/admin" ? pathname === "/admin" : pathname.startsWith(s.href)));
  const allowed = !section || data.permissions.includes(section.permission);
  // The overview needs analytics.view; other staff land on their first permitted section.
  const first = ADMIN_SECTIONS.find((s) => data.permissions.includes(s.permission));

  return (
    <div className="min-h-dvh md:grid md:grid-cols-[14rem_1fr]">
      <aside className="flex flex-col gap-4 border-b bg-card/60 p-4 md:sticky md:top-0 md:h-dvh md:border-e md:border-b-0">
        <div className="flex items-center justify-between md:flex-col md:items-start md:gap-1">
          <Link href="/admin"><Logo className="text-lg" /></Link>
          <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{t.admin.title}</span>
        </div>
        <AdminNav permissions={data.permissions} />
        <Link href="/home" className="mt-auto hidden items-center gap-2 px-3 text-sm text-muted-foreground hover:text-foreground md:flex">
          <ArrowLeft className="size-4 rtl:rotate-180" /> {t.admin.backToApp}
        </Link>
      </aside>
      <main className="min-w-0 px-4 py-6 md:px-8">
        <Suspense fallback={null}>
          {allowed ? children : first ? <RedirectTo href={first.href} /> : <EmptyState title={t.admin.noAccess} />}
        </Suspense>
      </main>
    </div>
  );
}

function RedirectTo({ href }: { href: string }) {
  const router = useRouter();
  useEffect(() => router.replace(href), [router, href]);
  return null;
}

/** GET helper for admin screens. */
export function useAdmin<T>(path: string, params: Record<string, string | number | undefined> = {}) {
  const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== "").map(([k, v]) => [k, String(v)])).toString();
  const url = qs ? `${path}?${qs}` : path;
  return useQuery({ queryKey: ["admin", url], queryFn: () => api<T>(`/admin${url}`) });
}

export type Paged<T> = { data: T[]; meta: { page: number; last_page: number; total: number } };

export function Pager({ meta, onPage }: { meta?: Paged<unknown>["meta"]; onPage: (p: number) => void }) {
  const { t, f } = useI18n();
  if (!meta || meta.last_page <= 1) return null;
  return (
    <div className="flex items-center justify-end gap-2 text-sm">
      <span className="text-muted-foreground">{f(t.admin.page, { page: meta.page, last: meta.last_page })}</span>
      <Button size="sm" variant="outline" disabled={meta.page <= 1} onClick={() => onPage(meta.page - 1)}>{t.admin.prev}</Button>
      <Button size="sm" variant="outline" disabled={meta.page >= meta.last_page} onClick={() => onPage(meta.page + 1)}>{t.admin.next}</Button>
    </div>
  );
}

export function AdminTitle({ children, description }: { children: React.ReactNode; description?: string }) {
  return (
    <div className="mb-6">
      <h1 className="font-display text-3xl">{children}</h1>
      {description && <p className="mt-1 max-w-3xl text-sm text-muted-foreground">{description}</p>}
    </div>
  );
}

/** Plain responsive table wrapper: scrolls horizontally on phones instead of breaking the layout. */
export function Table({ children }: { children: React.ReactNode }) {
  return (
    <div className="overflow-x-auto rounded-xl border bg-card">
      <table className="w-full min-w-[40rem] text-sm [&_td]:px-3 [&_td]:py-2.5 [&_th]:px-3 [&_th]:py-2 [&_th]:text-start [&_th]:font-medium [&_th]:text-muted-foreground [&_thead]:border-b [&_tbody_tr]:border-b [&_tbody_tr:last-child]:border-0">
        {children}
      </table>
    </div>
  );
}
