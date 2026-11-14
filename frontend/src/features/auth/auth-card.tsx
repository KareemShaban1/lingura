"use client";

import Link from "next/link";
import { Logo } from "@/components/layout/app-shell";
import { ThemeToggle } from "@/components/theme-toggle";

export function AuthCard({ title, subtitle, children, footer }: { title: string; subtitle?: string; children: React.ReactNode; footer?: React.ReactNode }) {
  return (
    <div className="relative flex min-h-dvh flex-col items-center justify-center bg-[radial-gradient(ellipse_at_top,var(--secondary),transparent_60%)] px-4 py-10">
      <ThemeToggle className="absolute end-4 top-4" />
      <Link href="/" className="mb-8">
        <Logo />
      </Link>
      <div className="w-full max-w-sm rounded-2xl border bg-card p-6 shadow-lg shadow-black/5 md:p-8">
        <h1 className="font-display text-2xl">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
        <div className="mt-6">{children}</div>
      </div>
      {footer && <div className="mt-6 text-sm text-muted-foreground">{footer}</div>}
    </div>
  );
}
