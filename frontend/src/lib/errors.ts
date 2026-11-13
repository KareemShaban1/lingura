"use client";

import { toast } from "sonner";
import { ApiError } from "./api";
import { dictionaries } from "./i18n";
import { useUiStore } from "@/stores/ui-store";

/**
 * Shows an API error as a toast. Plan limits (429 `quota_exceeded`) get a direct link to the plans page,
 * so a learner who hits a limit always has a real next step.
 */
/** Client-side navigation registered by the app shell (keeps SPA routing for the "See plans" action). */
let navigate: (href: string) => void = () => undefined;
export function setErrorNavigator(fn: (href: string) => void) {
  navigate = fn;
}

export function toastError(error: unknown, fallback: string) {
  const message = error instanceof Error ? error.message : fallback;
  if (isQuotaError(error)) {
    const t = dictionaries[useUiStore.getState().locale];
    toast.error(message, { action: { label: t.billing.seePlans, onClick: () => navigate("/pricing") }, duration: 8000 });
    return;
  }
  toast.error(message);
}

export const isQuotaError = (error: unknown) => error instanceof ApiError && error.code === "quota_exceeded";
