"use client";

import { useEffect, useState } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ThemeProvider } from "next-themes";
import { Toaster } from "sonner";
import { ApiError } from "@/lib/api";
import { localeDirection } from "@/lib/i18n";
import { useUiStore } from "@/stores/ui-store";

function LocaleSync() {
  const locale = useUiStore((s) => s.locale);
  useEffect(() => {
    document.documentElement.lang = locale;
    document.documentElement.dir = localeDirection[locale];
  }, [locale]);
  return null;
}

function ServiceWorker() {
  useEffect(() => {
    if (process.env.NODE_ENV === "production" && "serviceWorker" in navigator) {
      navigator.serviceWorker.register("/sw.js").catch(() => undefined);
    }
  }, []);
  return null;
}

export function Providers({ children }: { children: React.ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 30_000,
            refetchOnWindowFocus: false,
            // Client errors are final, except rate limiting (429), which is retried with backoff.
            retry: (count, error) =>
              error instanceof ApiError && error.status >= 400 && error.status < 500 ? error.status === 429 && count < 3 : count < 2,
            retryDelay: (attempt) => Math.min(1000 * 2 ** attempt, 8000),
          },
        },
      }),
  );
  const dir = useUiStore((s) => localeDirection[s.locale]);

  return (
    <QueryClientProvider client={client}>
      <ThemeProvider attribute="class" defaultTheme="system" enableSystem disableTransitionOnChange>
        <LocaleSync />
        <ServiceWorker />
        {children}
        <Toaster position="top-center" richColors dir={dir} />
      </ThemeProvider>
    </QueryClientProvider>
  );
}
