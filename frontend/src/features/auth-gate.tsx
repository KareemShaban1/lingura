"use client";

import Link from "next/link";
import { X } from "lucide-react";
import { AppShell, Logo } from "@/components/layout/app-shell";
import { ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { ApiError } from "@/lib/api";
import { useRequireAuth } from "./use-require-auth";

export function FullPageLoader() {
  return (
    <div className="grid min-h-dvh place-items-center" role="status" aria-live="polite">
      <div className="flex flex-col items-center gap-4">
        <Logo />
        <Skeleton className="h-1.5 w-40" />
      </div>
    </div>
  );
}

export function AppGate({ children }: { children: React.ReactNode }) {
  const { ready, data, error, refetch } = useRequireAuth();
  // A failed background refresh (e.g. rate limiting) must not hide an app that already has the user loaded.
  if (!data && error && !(error instanceof ApiError && error.isUnauthenticated)) {
    return (
      <div className="grid min-h-dvh place-items-center p-4">
        <ErrorState error={error} onRetry={() => refetch()} />
      </div>
    );
  }
  if (!ready) return <FullPageLoader />;
  return <AppShell>{children}</AppShell>;
}

/** Focused, chrome-free layout for onboarding and assessments. */
export function FocusGate({ children }: { children: React.ReactNode }) {
  const { data, error, refetch } = useRequireAuth({ requireProfile: false });
  if (!data && error && !(error instanceof ApiError && error.isUnauthenticated)) {
    return (
      <div className="grid min-h-dvh place-items-center p-4">
        <ErrorState error={error} onRetry={() => refetch()} />
      </div>
    );
  }
  if (!data) return <FullPageLoader />;
  return (
    <div className="min-h-dvh">
      <header className="flex h-14 items-center justify-between border-b px-4 md:px-8">
        <Logo className="text-lg" />
        {data.learner_profiles.length > 0 && (
          <Link href="/home" aria-label="Exit" className="rounded-md p-2 text-muted-foreground hover:bg-muted">
            <X className="size-5" />
          </Link>
        )}
      </header>
      <main className="mx-auto w-full max-w-2xl px-4 py-8 md:py-12">{children}</main>
    </div>
  );
}
