import { create } from "zustand";
import { persist } from "zustand/middleware";
import type { Locale } from "@/lib/i18n";

interface UiState {
  locale: Locale;
  activeProfileId: string | null;
  setLocale: (locale: Locale) => void;
  setActiveProfileId: (id: string | null) => void;
}

/** Client-only UI state. Server data lives in TanStack Query, never here. */
export const useUiStore = create<UiState>()(
  persist(
    (set) => ({
      locale: "en",
      activeProfileId: null,
      setLocale: (locale) => set({ locale }),
      setActiveProfileId: (activeProfileId) => set({ activeProfileId }),
    }),
    { name: "lf-ui" },
  ),
);
