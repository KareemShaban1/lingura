"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { Info, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { useConversationReport } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

export function ScoreBar({ label, score }: { label: string; score: number }) {
  return (
    <div>
      <div className="flex justify-between text-sm"><span>{label}</span><span className="font-mono">{score}/5</span></div>
      <div className="mt-1 flex gap-1" aria-hidden>
        {[1, 2, 3, 4, 5].map((i) => <span key={i} className={`h-1.5 flex-1 rounded-full ${i <= score ? "bg-primary" : "bg-muted"}`} />)}
      </div>
    </div>
  );
}

export function ConversationReportView() {
  const { id } = useParams<{ id: string }>();
  const { t } = useI18n();
  const qc = useQueryClient();
  const report = useConversationReport(id);

  if (report.error) return <ErrorState error={report.error} onRetry={() => report.refetch()} />;
  if (!report.data) return <Skeleton className="h-96" />;
  const d = report.data;
  const lang = d.conversation.language;

  if (d.status === "pending") {
    return <div className="flex flex-col items-center gap-3 py-20 text-muted-foreground" role="status"><Loader2 className="size-8 animate-spin" />{t.report.generating}</div>;
  }
  if (d.status === "failed") {
    return (
      <div className="flex flex-col items-center gap-3 py-20">
        <p>{t.report.failed}</p>
        <Button onClick={async () => { await api(`/conversations/${id}/report/retry`, { method: "POST" }); qc.invalidateQueries({ queryKey: ["conversation", id, "report"] }); }}>{t.report.retry}</Button>
      </div>
    );
  }
  if (d.status === "empty" || !d.report) return <p className="py-20 text-center text-muted-foreground">{t.report.empty}</p>;
  const r = d.report;

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <p className="text-sm text-muted-foreground">{d.conversation.tutor.name} · {d.conversation.scenario?.title} · {d.conversation.level.label}</p>
        <h1 className="font-display text-3xl md:text-4xl">{t.report.title}</h1>
      </div>

      <div className="grid gap-4 md:grid-cols-[1fr_16rem]">
        <Card><CardContent className="space-y-4 p-5">
          {(Object.keys(r.scores) as (keyof typeof r.scores)[]).map((k) => <ScoreBar key={k} label={t.report.scores[k]} score={r.scores[k]} />)}
        </CardContent></Card>
        <Card><CardHeader><CardTitle>{t.report.stats}</CardTitle></CardHeader>
          <CardContent className="grid grid-cols-2 gap-3 text-sm">
            <div><p className="font-display text-2xl">{d.stats.learner_turns}</p><p className="text-xs text-muted-foreground">{t.report.turnsStat}</p></div>
            <div><p className="font-display text-2xl">{d.stats.avg_words_per_turn}</p><p className="text-xs text-muted-foreground">{t.report.wordsStat}</p></div>
            <div><p className="font-display text-2xl">{d.stats.speaking_seconds}</p><p className="text-xs text-muted-foreground">{t.report.speakingStat}</p></div>
            <div><p className="font-display text-2xl">{d.stats.avg_response_seconds}</p><p className="text-xs text-muted-foreground">{t.report.responseStat}</p></div>
          </CardContent>
        </Card>
      </div>

      <Card><CardHeader><CardTitle>{t.report.summary}</CardTitle></CardHeader>
        <CardContent className="space-y-4 text-sm leading-relaxed">
          <p dir="auto">{r.summary}</p>
          <div><p className="font-semibold">{t.report.fluency}</p><p dir="auto" className="text-muted-foreground">{r.fluency_feedback}</p></div>
          {r.pronunciation_feedback && <div><p className="font-semibold">{t.report.pronunciation}</p><p dir="auto" className="text-muted-foreground">{r.pronunciation_feedback}</p></div>}
        </CardContent>
      </Card>

      <Card><CardHeader><CardTitle>{t.report.mistakes}</CardTitle></CardHeader>
        <CardContent>
          {d.mistakes.length === 0 ? <p className="text-sm text-muted-foreground">{t.report.noMistakes}</p> : (
            <ul className="space-y-3 text-sm">
              {d.mistakes.map((m, i) => (
                <li key={i} className="border-s-2 border-warning ps-3">
                  <p lang={lang.code} dir={lang.direction}><span className="line-through decoration-destructive/70">{m.original}</span> → <strong>{m.correction}</strong> <Badge variant="muted">{m.type}</Badge></p>
                  <p dir="auto" className="text-muted-foreground">{m.explanation}</p>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <div className="grid gap-4 md:grid-cols-2">
        <Card><CardHeader><CardTitle>{t.report.vocabulary}</CardTitle></CardHeader>
          <CardContent><ul className="space-y-2 text-sm">{r.vocabulary.map((v) => <li key={v.term}><strong lang={lang.code}>{v.term}</strong> — <span dir="auto" className="text-muted-foreground">{v.meaning}</span></li>)}</ul></CardContent>
        </Card>
        <Card><CardHeader><CardTitle>{t.report.expressions}</CardTitle></CardHeader>
          <CardContent><ul className="space-y-2 text-sm">{r.expressions.map((v) => <li key={v.term}><strong lang={lang.code}>{v.term}</strong> — <span dir="auto" className="text-muted-foreground">{v.meaning}</span></li>)}</ul></CardContent>
        </Card>
      </div>

      {r.exercises.length > 0 && (
        <Card><CardHeader><CardTitle>{t.report.exercises}</CardTitle></CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {r.exercises.map((e) => <Button key={e.id} asChild variant="outline" size="sm"><Link href={`/practice/${e.id}`}>{e.title ?? t.practice.types[e.type]}</Link></Button>)}
          </CardContent>
        </Card>
      )}

      <p className="flex items-start gap-2 rounded-lg bg-muted p-3 text-sm text-muted-foreground"><Info className="mt-0.5 size-4 shrink-0" /> {t.report.aiNote}</p>
      <Button asChild size="lg"><Link href="/tutor">{t.report.newConversation}</Link></Button>
    </div>
  );
}
