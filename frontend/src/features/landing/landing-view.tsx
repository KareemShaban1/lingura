"use client";

import Link from "next/link";
import { ArrowRight, Check } from "lucide-react";
import { ThemeToggle } from "@/components/theme-toggle";
import { Logo } from "@/components/layout/app-shell";
import { LevelLadder, SkillIcon } from "@/components/skill";
import { Button } from "@/components/ui/button";
import { useI18n } from "@/lib/i18n";
import type { SkillSlug } from "@/lib/types";
import { useUiStore } from "@/stores/ui-store";

/** Illustrative example profile on the landing page (clearly labelled; not user data). */
const EXAMPLE: [SkillSlug, number, string][] = [
  ["listening", 3, "B1"],
  ["reading", 4, "B2"],
  ["grammar", 3, "B1"],
  ["vocabulary", 2, "A2"],
];

export function LandingView() {
  const { t, locale } = useI18n();
  const setLocale = useUiStore((s) => s.setLocale);

  return (
    <div className="min-h-dvh overflow-x-hidden">
      <header className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 md:px-8">
        <Logo />
        <div className="flex items-center gap-2">
          <ThemeToggle />
          <Button variant="ghost" size="sm" onClick={() => setLocale(locale === "en" ? "ar" : "en")}>
            {locale === "en" ? "العربية" : "English"}
          </Button>
          <Button asChild variant="outline" size="sm">
            <Link href="/login">{t.auth.signIn}</Link>
          </Button>
        </div>
      </header>

      <section className="mx-auto grid max-w-6xl grid-cols-1 items-center gap-12 px-4 pb-16 pt-10 md:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] md:px-8 md:pt-20">
        <div>
          <p className="text-sm font-medium uppercase tracking-[0.14em] text-primary">{t.landing.eyebrow}</p>
          <h1 className="mt-4 font-display text-4xl leading-[1.08] tracking-tight text-balance md:text-6xl">{t.landing.title}</h1>
          <p className="mt-5 max-w-xl text-lg text-muted-foreground text-pretty">{t.landing.subtitle}</p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Button asChild size="lg">
              <Link href="/register">
                {t.landing.ctaPrimary} <ArrowRight className="rtl:rotate-180" />
              </Link>
            </Button>
            <Button asChild size="lg" variant="ghost">
              <Link href="/login">{t.landing.ctaSecondary}</Link>
            </Button>
          </div>
        </div>

        <figure className="relative">
          <div className="absolute -inset-6 -z-10 rounded-[2rem] bg-[radial-gradient(ellipse_at_top,var(--secondary),transparent_70%)]" />
          <div className="rounded-2xl border bg-card p-5 shadow-xl shadow-black/5">
            <div className="flex items-baseline justify-between">
              <p className="text-sm text-muted-foreground">Español · {t.results.bySkill}</p>
              <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">example</span>
            </div>
            <ul className="mt-4 space-y-4">
              {EXAMPLE.map(([skill, ordinal, label]) => (
                <li key={skill} className="flex items-center gap-3">
                  <SkillIcon skill={skill} />
                  <div className="flex-1">
                    <div className="flex justify-between text-sm">
                      <span className="font-medium">{t.skills[skill]}</span>
                      <span className="font-mono text-xs">{label}</span>
                    </div>
                    <LevelLadder ordinal={ordinal} skill={skill} className="mt-2" />
                  </div>
                </li>
              ))}
            </ul>
            <div className="mt-5 rounded-lg bg-muted p-3 text-sm">
              <p className="font-medium">{t.learning.rationale}</p>
              <p className="mt-1 text-muted-foreground">{t.results.findings.prioritise_skill.replace("{skill}", t.skills.vocabulary)}</p>
            </div>
          </div>
        </figure>
      </section>

      <section className="border-y bg-card/50">
        <div className="mx-auto grid max-w-6xl gap-8 px-4 py-14 md:grid-cols-3 md:px-8">
          {t.landing.pillars.map((p) => (
            <div key={p.title}>
              <h2 className="font-display text-2xl">{p.title}</h2>
              <p className="mt-2 text-muted-foreground">{p.body}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 md:px-8">
        <h2 className="font-display text-3xl">{t.landing.howTitle}</h2>
        <ol className="mt-8 grid gap-4 md:grid-cols-3">
          {t.landing.steps.map((step, i) => (
            <li key={step} className="flex gap-4 rounded-xl border bg-card p-5">
              <span className="grid size-8 shrink-0 place-items-center rounded-full bg-primary font-mono text-sm text-primary-foreground">{i + 1}</span>
              <span className="pt-1">{step}</span>
            </li>
          ))}
        </ol>
        <p className="mt-10 flex items-start gap-2 text-sm text-muted-foreground">
          <Check className="mt-0.5 size-4 text-success" aria-hidden /> {t.landing.footer}
        </p>
      </section>
    </div>
  );
}
