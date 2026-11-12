"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, XCircle } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { ExerciseCard } from "@/features/practice/practice-view";
import { useActiveProfile, useGrammarTopic, useGrammarTopics } from "@/features/queries";
import { useI18n } from "@/lib/i18n";
import type { GrammarTopic } from "@/lib/types";

function MasteryLine({ topic }: { topic: GrammarTopic }) {
  const { t } = useI18n();
  if (!topic.mastery) return <p className="text-xs text-muted-foreground">{t.grammar.notPractised}</p>;
  if (!topic.mastery.enough_evidence || topic.mastery.value === null) return <p className="text-xs text-muted-foreground">{t.grammar.notEnough}</p>;
  const pct = Math.round(topic.mastery.value * 100);
  return (
    <div className="flex items-center gap-3">
      <Progress value={pct} className="h-1.5" indicatorClassName={pct >= 80 ? "bg-success" : pct >= 60 ? "bg-primary" : "bg-warning"} aria-label={t.grammar.mastery} />
      <span className="w-10 text-end font-mono text-xs">{pct}%</span>
    </div>
  );
}

export function GrammarListView() {
  const { t } = useI18n();
  const profile = useActiveProfile();
  const topics = useGrammarTopics(profile?.id);
  if (topics.error) return <ErrorState error={topics.error} onRetry={() => topics.refetch()} />;
  return (
    <div className="space-y-6">
      <Link href="/practice" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4 rtl:rotate-180" /> {t.practice.title}</Link>
      <h1 className="font-display text-3xl md:text-4xl">{t.grammar.title}</h1>
      {!topics.data ? <Skeleton className="h-64" /> : (
        <div className="grid gap-3 sm:grid-cols-2">
          {topics.data.map((topic) => (
            <Link key={topic.slug} href={`/practice/grammar/${topic.slug}`}>
              <Card className="h-full hover:border-primary/50">
                <CardContent className="space-y-3 p-4">
                  <div className="flex items-start justify-between gap-2">
                    <p className="font-medium">{topic.title}</p>
                    <Badge variant="outline" className="font-mono">{topic.level.label}</Badge>
                  </div>
                  <MasteryLine topic={topic} />
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}

export function GrammarTopicView() {
  const { slug } = useParams<{ slug: string }>();
  const { t } = useI18n();
  const profile = useActiveProfile();
  const topic = useGrammarTopic(profile?.id, slug);
  if (topic.error) return <ErrorState error={topic.error} onRetry={() => topic.refetch()} />;
  if (!topic.data) return <Skeleton className="h-96" />;
  const d = topic.data;
  return (
    <div className="space-y-6">
      <Link href="/practice/grammar" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4 rtl:rotate-180" /> {t.grammar.title}</Link>
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="font-display text-3xl md:text-4xl">{d.title}</h1>
        <Badge variant="outline" className="font-mono">{d.level.label}</Badge>
      </div>
      <MasteryLine topic={d} />
      <Card>
        <CardHeader><CardTitle>{t.grammar.explanation}</CardTitle></CardHeader>
        <CardContent className="space-y-4">
          <p className="leading-relaxed">{d.explanation}</p>
          <ul className="space-y-2">
            {d.examples?.map((e) => (
              <li key={e.sentence} className="rounded-lg bg-muted px-4 py-3">
                <p lang={d.language.code} dir={d.language.direction} className="text-lg">{e.sentence}</p>
                {e.translation && <p dir="auto" className="text-sm text-muted-foreground">{e.translation}</p>}
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>
      <section>
        <h2 className="mb-3 text-lg font-semibold">{t.grammar.practise}</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          {d.exercises.map((e) => <ExerciseCard key={e.id} exercise={e} href={`/practice/${e.id}`} />)}
        </div>
      </section>
      <Card>
        <CardHeader><CardTitle>{t.grammar.mistakes}</CardTitle></CardHeader>
        <CardContent>
          {d.recent_mistakes.length === 0 ? <EmptyState title={t.grammar.noMistakes} className="border-0 p-2" /> : (
            <ul className="space-y-3 text-sm">
              {d.recent_mistakes.map((m, i) => (
                <li key={i} className="flex gap-3">
                  <XCircle className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden />
                  <div>
                    <p lang={d.language.code} dir={d.language.direction}>{m.prompt}</p>
                    <p className="text-muted-foreground">{t.grammar.you}: <span dir="auto">{m.given ?? "—"}</span> · {t.grammar.answer}: <strong className="text-foreground" dir="auto">{m.correct}</strong></p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
