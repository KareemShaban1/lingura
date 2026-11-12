"use client";

import { useCallback } from "react";
import { ar } from "./ar";
import { en, type Dictionary } from "./en";
import { useUiStore } from "@/stores/ui-store";

export type Locale = "en" | "ar";
export const dictionaries: Record<Locale, Dictionary> = { en, ar };
export const localeDirection: Record<Locale, "ltr" | "rtl"> = { en: "ltr", ar: "rtl" };

/** Replaces {placeholders} in a translated string. */
export function format(template: string, params: Record<string, string | number> = {}): string {
  return template.replace(/\{(\w+)\}/g, (_, key) => String(params[key] ?? `{${key}}`));
}

export function useI18n() {
  const locale = useUiStore((s) => s.locale);
  const t = dictionaries[locale];
  const f = useCallback((template: string, params?: Record<string, string | number>) => format(template, params), []);
  return { t, f, locale, dir: localeDirection[locale] };
}
