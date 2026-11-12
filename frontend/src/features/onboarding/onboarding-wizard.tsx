"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { toast } from "sonner";
import { Choice } from "@/components/choice";
import { SKILLS, SkillIcon } from "@/components/skill";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { keys, useCatalog, useMe } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import type { AssessmentAttempt, LearnerProfile, SkillSlug } from "@/lib/types";
import { cn } from "@/lib/utils";
import { useUiStore } from "@/stores/ui-store";

type Draft = {
  target_language?: string;
  variant?: string;
  purpose?: string;
  self_reported_level?: string | null;
  daily_minutes: number;
  days: number[];
  time: "morning" | "afternoon" | "evening";
  focus_skills: SkillSlug[];
};

const STEPS = ["language", "variant", "purpose", "level", "time", "focus"] as const;
type Step = (typeof STEPS)[number];

export function OnboardingWizard() {
  const { t, f } = useI18n();
  const catalog = useCatalog();
  const me = useMe();
  const router = useRouter();
  const qc = useQueryClient();
  const setActive = useUiStore((s) => s.setActiveProfileId);
  const [stepIndex, setStepIndex] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [draft, setDraft] = useState<Draft>({ daily_minutes: 20, days: [1, 2, 3, 4, 5], time: "evening", focus_skills: [] });

  const language = catalog.data?.languages.find((l) => l.code === draft.target_language);
  const learning = new Set(me.data?.learner_profiles.map((p) => p.target_language.code));
  // Skip the variant step for languages with a single variety.
  const steps = useMemo<Step[]>(() => STEPS.filter((s) => s !== "variant" || (language?.variants?.length ?? 0) > 1), [language]);
  const step = steps[stepIndex];
  const update = (patch: Partial<Draft>) => setDraft((d) => ({ ...d, ...patch }));

  const canContinue: Record<Step, boolean> = {
    language: !!draft.target_language,
    variant: !!draft.variant,
    purpose: !!draft.purpose,
    level: draft.self_reported_level !== undefined,
    time: draft.days.length > 0,
    focus: true,
  };

  async function submit() {
    setSubmitting(true);
    try {
      const { data: profile } = await api<{ data: LearnerProfile }>("/learner-profiles", {
        method: "POST",
        body: {
          target_language: draft.target_language,
          variant: draft.variant,
          purpose: draft.purpose,
          self_reported_level: draft.self_reported_level || null,
          daily_minutes: draft.daily_minutes,
          schedule: draft.days.map((day) => ({ day, time: draft.time })),
          focus_skills: draft.focus_skills,
        },
      });
      setActive(profile.id);
      await qc.invalidateQueries({ queryKey: keys.me });
      const { data: attempt } = await api<{ data: AssessmentAttempt }>(`/learner-profiles/${profile.id}/assessments`, { method: "POST" });
      router.push(`/assessment/${attempt.id}`);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t.common.errorTitle);
      setSubmitting(false);
    }
  }

  if (catalog.isPending) return <div className="space-y-3"><Skeleton className="h-8 w-2/3" />{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-16" />)}</div>;
  if (catalog.error) return <ErrorState error={catalog.error} onRetry={() => catalog.refetch()} />;
  const c = catalog.data;

  const titles: Record<Step, { title: string; subtitle: string }> = {
    language: t.onboarding.language,
    variant: t.onboarding.variant,
    purpose: t.onboarding.purpose,
    level: t.onboarding.level,
    time: t.onboarding.time,
    focus: t.onboarding.focus,
  };

  return (
    <div>
      <div className="mb-8 space-y-3">
        <p className="text-sm text-muted-foreground">{f(t.onboarding.stepOf, { current: stepIndex + 1, total: steps.length })}</p>
        <Progress value={((stepIndex + 1) / steps.length) * 100} aria-label={t.onboarding.title} />
      </div>
      <h1 className="font-display text-3xl text-balance">{titles[step].title}</h1>
      <p className="mt-2 text-muted-foreground">{titles[step].subtitle}</p>

      <div className="mt-8">
        {step === "language" && (
          <div role="radiogroup" className="grid gap-3 sm:grid-cols-2">
            {c.languages.filter((l) => l.is_target_enabled).map((l) => (
              <Choice
                key={l.code}
                selected={draft.target_language === l.code}
                disabled={learning.has(l.code)}
                onSelect={() => update({ target_language: l.code, variant: l.variants?.find((v) => v.is_default)?.code ?? l.variants?.[0]?.code })}
                title={l.native_name}
                lang={l.code}
                dir={l.direction}
                description={learning.has(l.code) ? t.onboarding.alreadyLearning : l.name}
              />
            ))}
          </div>
        )}

        {step === "variant" && language && (
          <div role="radiogroup" className="grid gap-3">
            {language.variants?.map((v) => (
              <Choice key={v.code} selected={draft.variant === v.code} onSelect={() => update({ variant: v.code })} title={v.name} description={v.code} />
            ))}
          </div>
        )}

        {step === "purpose" && (
          <div role="radiogroup" className="grid gap-3 sm:grid-cols-2">
            {c.purposes.map((p) => (
              <Choice key={p.slug} selected={draft.purpose === p.slug} onSelect={() => update({ purpose: p.slug })} title={t.purposes[p.slug as keyof typeof t.purposes] ?? p.name} />
            ))}
          </div>
        )}

        {step === "level" && (
          <div role="radiogroup" className="grid gap-2">
            {c.levels.map((l) => (
              <Choice
                key={l.code}
                selected={draft.self_reported_level === l.code}
                onSelect={() => update({ self_reported_level: l.code })}
                title={t.onboarding.level.descriptions[l.code as keyof typeof t.onboarding.level.descriptions]}
                aside={<Badge variant="outline" className="font-mono">{l.label}</Badge>}
              />
            ))}
            <Choice selected={draft.self_reported_level === null} onSelect={() => update({ self_reported_level: null })} title={t.onboarding.level.unsure} />
          </div>
        )}

        {step === "time" && (
          <div className="space-y-8">
            <div role="radiogroup" className="flex flex-wrap gap-2">
              {c.daily_minutes.suggested.map((m) => (
                <button
                  key={m}
                  type="button"
                  role="radio"
                  aria-checked={draft.daily_minutes === m}
                  onClick={() => update({ daily_minutes: m })}
                  className={cn("rounded-full border px-4 py-2 text-sm font-medium transition-colors", draft.daily_minutes === m ? "border-primary bg-primary text-primary-foreground" : "bg-card hover:bg-muted")}
                >
                  {f(t.onboarding.time.perDay, { minutes: m })}
                </button>
              ))}
            </div>
            <fieldset>
              <legend className="mb-3 text-sm font-medium">{t.onboarding.time.days}</legend>
              <div className="grid grid-cols-7 gap-1.5">
                {t.common.daysShort.map((d, i) => {
                  const on = draft.days.includes(i);
                  return (
                    <button
                      key={d}
                      type="button"
                      role="checkbox"
                      aria-checked={on}
                      aria-label={t.common.days[i]}
                      onClick={() => update({ days: on ? draft.days.filter((x) => x !== i) : [...draft.days, i].sort() })}
                      className={cn("rounded-lg border py-3 text-xs font-medium transition-colors", on ? "border-primary bg-secondary text-secondary-foreground" : "bg-card text-muted-foreground hover:bg-muted")}
                    >
                      {d}
                    </button>
                  );
                })}
              </div>
            </fieldset>
            <fieldset>
              <legend className="mb-3 text-sm font-medium">{t.onboarding.time.time}</legend>
              <div role="radiogroup" className="grid grid-cols-3 gap-2">
                {(["morning", "afternoon", "evening"] as const).map((slot) => (
                  <Choice key={slot} selected={draft.time === slot} onSelect={() => update({ time: slot })} title={t.onboarding.time[slot]} />
                ))}
              </div>
            </fieldset>
          </div>
        )}

        {step === "focus" && (
          <div role="group" className="grid gap-3 sm:grid-cols-2">
            {SKILLS.map((s) => {
              const on = draft.focus_skills.includes(s);
              return (
                <Choice
                  key={s}
                  multi
                  selected={on}
                  onSelect={() => update({ focus_skills: on ? draft.focus_skills.filter((x) => x !== s) : [...draft.focus_skills, s] })}
                  title={<span className="flex items-center gap-3"><SkillIcon skill={s} />{t.skills[s]}</span>}
                />
              );
            })}
          </div>
        )}
      </div>

      <div className="mt-10 flex items-center justify-between gap-3">
        <Button variant="ghost" onClick={() => setStepIndex((i) => i - 1)} disabled={stepIndex === 0 || submitting}>
          <ArrowLeft className="rtl:rotate-180" /> {t.common.back}
        </Button>
        {stepIndex < steps.length - 1 ? (
          <Button onClick={() => setStepIndex((i) => i + 1)} disabled={!canContinue[step]}>
            {t.common.continue} <ArrowRight className="rtl:rotate-180" />
          </Button>
        ) : (
          <Button size="lg" onClick={submit} loading={submitting}>
            {submitting ? t.onboarding.creating : t.onboarding.finish}
          </Button>
        )}
      </div>
    </div>
  );
}
