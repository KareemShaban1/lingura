"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowRight, Info, Lightbulb, TrendingDown, TrendingUp } from "lucide-react";
import { LevelLadder, SkillIcon } from "@/components/skill";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { useReport } from "@/features/queries";
import { useI18n } from "@/lib/i18n";
import type { AssessmentReport } from "@/lib/types";

export function useFindingText() {
  const { t, f } = useI18n();
  return (finding: AssessmentReport["findings"][number]) => {
    const template = t.results.findings[finding.code as keyof typeof t.results.findings];
    if (!template) return finding.code;
    return f(template, { skill: finding.skill ? t.skills[finding.skill] : "", detail: finding.detail_label ?? "" });
  };
}

export function ResultsView() {
  const { attemptId } = useParams<{ attemptId: string }>();
  const { t, f } = useI18n();
  const report = useReport(attemptId);
  const findingText = useFindingText();

  if (report.error) return <ErrorState error={report.error} onRetry={() => report.refetch()} />;
  if (!report.data) return <div className="space-y-4"><Skeleton className="h-40" /><Skeleton className="h-64" /></div>;
  const r = report.data;
  const strengths = r.findings.filter((x) => x.type === "strength");
  const weaknesses = r.findings.filter((x) => x.type === "weakness");
  const recommendations = r.findings.filter((x) => x.type === "recommendation");

  return (
    <div className="space-y-8">
      <div>
        <p className="text-sm font-medium uppercase tracking-[0.14em] text-primary">{t.results.title}</p>
        <div className="mt-4 flex flex-wrap items-end gap-4">
          <div>
            <p className="text-sm text-muted-foreground">{t.results.overall}</p>
            <p className="font-display text-7xl leading-none">{r.overall.level?.label ?? "—"}</p>
          </div>
          {r.overall.is_provisional && <Badge variant="warning" className="mb-2">{t.results.provisional}</Badge>}
        </div>
        <p className="mt-4 text-sm text-muted-foreground">{t.results.method}</p>
        {r.overall.is_provisional && r.overall.provisional_reasons.length > 0 && (
          <details className="mt-3 rounded-lg border bg-card p-3 text-sm">
            <summary className="cursor-pointer font-medium">{t.results.provisionalWhy}</summary>
            <ul className="mt-2 list-disc space-y-1 ps-5 text-muted-foreground">
              {r.overall.provisional_reasons.map((reason) => (
                <li key={reason}>{t.results.provisionalReasons[reason as keyof typeof t.results.provisionalReasons] ?? reason}</li>
              ))}
            </ul>
          </details>
        )}
      </div>

      <section aria-labelledby="by-skill">
        <h2 id="by-skill" className="mb-3 text-lg font-semibold">{t.results.bySkill}</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          {r.skills.map((s) => (
            <Card key={s.skill} className={s.status !== "completed" ? "bg-muted/40" : undefined}>
              <CardContent className="flex gap-3 p-4">
                <SkillIcon skill={s.skill} />
                <div className="min-w-0 flex-1">
                  <div className="flex items-baseline justify-between gap-2">
                    <p className="font-medium">{t.skills[s.skill]}</p>
                    <p className="font-mono text-lg">{s.level?.label ?? "—"}</p>
                  </div>
                  {s.status === "completed" ? (
                    <>
                      <LevelLadder ordinal={s.level?.ordinal ?? null} skill={s.skill} className="mt-2" />
                      <p className="mt-2 text-xs text-muted-foreground">
                        {s.confidence && t.results.confidence[s.confidence]}
                        {s.evaluation ? ` · ${t.report.aiNote}` : ` · ${f(t.results.answered, { correct: s.items_correct, answered: s.items_answered })}`}
                      </p>
                      {s.evaluation && (
                        <details className="mt-2 text-xs">
                          <summary className="cursor-pointer font-medium">{t.placement.evaluation}</summary>
                          <p dir="auto" className="mt-1 text-muted-foreground">{s.evaluation.summary}</p>
                          <ul className="mt-1 space-y-0.5">
                            {s.evaluation.criteria.map((c) => <li key={c.criterion}>{t.writing.criteria[c.criterion as keyof typeof t.writing.criteria] ?? c.criterion}: {c.score}/5</li>)}
                          </ul>
                        </details>
                      )}
                    </>
                  ) : (
                    <p className="mt-1 text-xs text-muted-foreground">
                      {t.assessment.sectionStates[s.status]}
                      {s.reason && ` · ${t.assessment.reasons[s.reason as keyof typeof t.assessment.reasons] ?? s.reason}`}
                    </p>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      </section>

      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader><CardTitle className="flex items-center gap-2"><TrendingUp className="size-4 text-success" aria-hidden /> {t.results.strengths}</CardTitle></CardHeader>
          <CardContent>
            {strengths.length ? <ul className="space-y-2 text-sm">{strengths.map((x, i) => <li key={i}>{findingText(x)}</li>)}</ul> : <p className="text-sm text-muted-foreground">{t.results.noStrengths}</p>}
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle className="flex items-center gap-2"><TrendingDown className="size-4 text-destructive" aria-hidden /> {t.results.weaknesses}</CardTitle></CardHeader>
          <CardContent>
            {weaknesses.length ? <ul className="space-y-2 text-sm">{weaknesses.map((x, i) => <li key={i}>{findingText(x)}</li>)}</ul> : <p className="text-sm text-muted-foreground">{t.results.noWeaknesses}</p>}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader><CardTitle className="flex items-center gap-2"><Lightbulb className="size-4 text-accent" aria-hidden /> {t.results.recommendations}</CardTitle></CardHeader>
        <CardContent>
          <ol className="list-decimal space-y-2 ps-5 text-sm">{recommendations.map((x, i) => <li key={i}>{findingText(x)}</li>)}</ol>
        </CardContent>
      </Card>

      <p className="flex items-start gap-2 rounded-lg bg-muted p-3 text-sm text-muted-foreground">
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden /> {r.disclaimer}
      </p>

      <div className="flex flex-wrap gap-3">
        <Button asChild size="lg"><Link href="/learning">{t.results.goToPlan} <ArrowRight className="rtl:rotate-180" /></Link></Button>
        <Button asChild size="lg" variant="outline"><Link href="/home">{t.results.goHome}</Link></Button>
      </div>
    </div>
  );
}
