"use client";

import { Moon, Sun } from "lucide-react";
import { useTheme } from "next-themes";
import { Button } from "@/components/ui/button";
import { useI18n } from "@/lib/i18n";
import { cn } from "@/lib/utils";

/**
 * Switches between light and dark. The icon follows the `dark` class (set before paint by next-themes),
 * so it is right on first render without a hydration mismatch. The choice is remembered on this device;
 * Profile → Appearance also offers "System".
 */
export function ThemeToggle({ className }: { className?: string }) {
  const { resolvedTheme, setTheme } = useTheme();
  const { t } = useI18n();
  return (
    <Button variant="ghost" size="icon" className={cn("shrink-0", className)} aria-label={t.common.toggleTheme} title={t.common.toggleTheme}
      onClick={() => setTheme(resolvedTheme === "dark" ? "light" : "dark")}>
      <Sun className="hidden dark:block" aria-hidden />
      <Moon className="dark:hidden" aria-hidden />
    </Button>
  );
}
