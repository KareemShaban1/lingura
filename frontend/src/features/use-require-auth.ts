"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { ApiError } from "@/lib/api";
import { useUiStore } from "@/stores/ui-store";
import { useMe } from "./queries";

/**
 * Client-side session guard. Authority stays on the API (every endpoint is protected by Sanctum + policies);
 * this only routes the user to the right screen.
 */
export function useRequireAuth({ requireProfile = true } = {}) {
  const me = useMe();
  const router = useRouter();
  const pathname = usePathname();
  const setLocale = useUiStore((s) => s.setLocale);

  const unauthenticated = me.error instanceof ApiError && me.error.isUnauthenticated;
  const needsOnboarding = requireProfile && me.data && me.data.learner_profiles.length === 0;

  useEffect(() => {
    if (unauthenticated) router.replace(`/login?next=${encodeURIComponent(pathname)}`);
    else if (needsOnboarding) router.replace("/onboarding");
  }, [unauthenticated, needsOnboarding, router, pathname]);

  useEffect(() => {
    if (me.data) setLocale(me.data.profile.interface_language);
  }, [me.data, setLocale]);

  return { ...me, ready: !!me.data && !needsOnboarding };
}
