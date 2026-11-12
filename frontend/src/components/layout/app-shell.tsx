"use client";

import { Suspense } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { useTheme } from "next-themes";
import { BarChart3, Bot, Dumbbell, Home, Layers, LogOut, Map, Moon, Plus, Sun, UserRound, type LucideIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { useActiveProfile, useMe } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import { useUiStore } from "@/stores/ui-store";

type NavItem = { href: string; key: "home" | "learning" | "practice" | "tutor" | "vocabulary" | "progress" | "profile"; icon: LucideIcon; feature?: string; mobile?: boolean };

/** Only modules that are enabled for this account are rendered — no dead navigation. */
const NAV: NavItem[] = [
  { href: "/home", key: "home", icon: Home },
  { href: "/learning", key: "learning", icon: Map, feature: "plan" },
  { href: "/practice", key: "practice", icon: Dumbbell, feature: "practice" },
  { href: "/tutor", key: "tutor", icon: Bot, feature: "tutor" },
  // On phones vocabulary is reached from Practice to keep the tab bar at five items.
  { href: "/vocabulary", key: "vocabulary", icon: Layers, feature: "vocabulary", mobile: false },
  { href: "/progress", key: "progress", icon: BarChart3, feature: "progress" },
  // On phones the profile lives in the header to keep the tab bar at five items.
  { href: "/profile", key: "profile", icon: UserRound, mobile: false },
];

function useNav() {
  const { data } = useMe();
  return NAV.filter((n) => !n.feature || data?.features.includes(n.feature));
}

function NavLinks({ variant }: { variant: "side" | "bottom" }) {
  const pathname = usePathname();
  const { t } = useI18n();
  const items = useNav().filter((n) => variant === "side" || n.mobile !== false);
  return (
    <>
      {items.map(({ href, key, icon: Icon }) => {
        const active = pathname === href || pathname.startsWith(`${href}/`);
        return (
          <Link
            key={href}
            href={href}
            aria-current={active ? "page" : undefined}
            className={cn(
              variant === "side"
                ? "flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                : "flex flex-1 flex-col items-center gap-1 py-2 text-[11px] font-medium text-muted-foreground",
              active && (variant === "side" ? "bg-secondary text-secondary-foreground hover:bg-secondary" : "text-primary"),
            )}
          >
            <Icon className={variant === "side" ? "size-4" : "size-5"} aria-hidden />
            {t.nav[key]}
          </Link>
        );
      })}
    </>
  );
}

function ProfileSwitcher() {
  const { data } = useMe();
  const active = useActiveProfile();
  const setActive = useUiStore((s) => s.setActiveProfileId);
  const router = useRouter();
  const { t } = useI18n();
  if (!data || !active) return null;

  return (
    <label className="flex items-center gap-2 text-sm">
      <span className="sr-only">{t.nav.learningLanguage}</span>
      <Select
        value={active.id}
        className="h-9 w-auto min-w-36"
        onChange={(e) => (e.target.value === "__add" ? router.push("/onboarding") : setActive(e.target.value))}
      >
        {data.learner_profiles.map((p) => (
          <option key={p.id} value={p.id}>
            {p.target_language.native_name}
          </option>
        ))}
        <option value="__add">+ {t.nav.addLanguage}</option>
      </Select>
    </label>
  );
}

function ThemeToggle() {
  const { resolvedTheme, setTheme } = useTheme();
  return (
    <Button variant="ghost" size="icon" aria-label="Toggle theme" onClick={() => setTheme(resolvedTheme === "dark" ? "light" : "dark")}>
      <Sun className="hidden dark:block" />
      <Moon className="dark:hidden" />
    </Button>
  );
}

export function useSignOut() {
  const qc = useQueryClient();
  const router = useRouter();
  return async () => {
    await api("/auth/logout", { method: "POST" }).catch(() => undefined);
    qc.clear();
    router.replace("/login");
  };
}

export function Logo({ className }: { className?: string }) {
  return (
    <span className={cn("flex items-center gap-2 font-display text-xl tracking-tight", className)}>
      <span className="grid size-8 place-items-center rounded-lg bg-primary text-primary-foreground font-sans text-sm font-bold">L</span>
      LinguaForge
    </span>
  );
}

export function AppShell({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  const signOut = useSignOut();
  const navFallback = <div className="h-40" />;

  return (
    <div className="min-h-dvh md:grid md:grid-cols-[15rem_1fr]">
      <aside className="sticky top-0 hidden h-dvh flex-col gap-6 border-e bg-card/60 p-4 md:flex">
        <Link href="/home" className="px-2 pt-1">
          <Logo />
        </Link>
        <nav className="flex flex-col gap-1">
          <Suspense fallback={navFallback}>
            <NavLinks variant="side" />
          </Suspense>
        </nav>
        <div className="mt-auto flex flex-col gap-1">
          <Link href="/onboarding" className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted">
            <Plus className="size-4" aria-hidden /> {t.nav.addLanguage}
          </Link>
          <button onClick={signOut} className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted">
            <LogOut className="size-4 rtl:rotate-180" aria-hidden /> {t.common.signOut}
          </button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-col">
        <header className="sticky top-0 z-30 flex h-14 items-center justify-between gap-3 border-b bg-background/85 px-4 backdrop-blur md:px-8">
          <Link href="/home" className="md:hidden">
            <Logo className="text-lg" />
          </Link>
          <div className="hidden md:block" />
          <div className="flex items-center gap-1">
            <ProfileSwitcher />
            <ThemeToggle />
            <Button asChild variant="ghost" size="icon" className="md:hidden" aria-label={t.nav.profile}>
              <Link href="/profile"><UserRound /></Link>
            </Button>
          </div>
        </header>
        <main className="mx-auto w-full max-w-5xl flex-1 px-4 pb-28 pt-6 md:px-8 md:pb-12">{children}</main>
      </div>

      <nav className="fixed inset-x-0 bottom-0 z-30 flex border-t bg-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden">
        <Suspense fallback={<div className="h-14" />}>
          <NavLinks variant="bottom" />
        </Suspense>
      </nav>
    </div>
  );
}
