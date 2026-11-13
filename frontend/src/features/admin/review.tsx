"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { CheckCircle2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { api } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import { AdminTitle, Pager, Table, useAdmin, type Paged } from "./admin-shell";

type Item = {
  id: string; language: string; skill: string; level: string; format: string; source: string; status: string; difficulty: number;
  stimulus: string | null; prompt: string; explanation: string | null; topic: string | null; options: { label: string; is_correct: boolean }[];
};
type Evaluation = {
  id: number; language: string; direction: "ltr" | "rtl"; task: string; text: string; prompt_version: string; model: string;
  ai: { overall: number; level: string; criteria: { criterion: string; score: number; comment: string }[] };
  human: { overall: number; notes: string | null; reviewed_at: string } | null;
};
type Calibration = { prompt_version: string; reviewed: number; mean_absolute_error: number; within_half_band: number }[];

export function AdminReview() {
  const { t } = useI18n();
  const [tab, setTab] = useState<"items" | "evaluations">("items");
  return (
    <div className="space-y-4">
      <AdminTitle>{t.admin.nav.review}</AdminTitle>
      <div role="tablist" className="inline-flex rounded-lg border bg-card p-1 text-sm">
        {(["items", "evaluations"] as const).map((k) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
            className={cn("rounded-md px-3 py-1.5 font-medium", tab === k ? "bg-primary text-primary-foreground" : "text-muted-foreground")}>
            {t.admin.review[k]}
          </button>
        ))}
      </div>
      {tab === "items" ? <ItemQueue /> : <EvaluationQueue />}
    </div>
  );
}

function ItemQueue() {
  const { t } = useI18n();
  const r = t.admin.review;
  const [page, setPage] = useState(1);
  const list = useAdmin<Paged<Item>>("/review/items", { page });
  const qc = useQueryClient();
  const [notes, setNotes] = useState<Record<string, string>>({});

  async function decide(id: string, decision: "approved" | "rejected") {
    try {
      await api(`/admin/review/items/${id}`, { method: "POST", body: { decision, notes: notes[id] || null } });
      toast.success(t.admin.saved);
      qc.invalidateQueries({ queryKey: ["admin"] });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  if (list.error) return <ErrorState error={list.error} onRetry={() => list.refetch()} />;
  if (!list.data) return <Skeleton className="h-96" />;
  if (list.data.data.length === 0) return <EmptyState title={t.admin.none} />;
  return (
    <div className="space-y-3">
      {list.data.data.map((item) => (
        <Card key={item.id}>
          <CardContent className="space-y-3 p-4">
            <div className="flex flex-wrap gap-1.5">
              {[item.language, item.skill, item.level, item.format, item.source].map((v) => <Badge key={v} variant="outline">{v}</Badge>)}
              {item.topic && <Badge variant="muted">{item.topic}</Badge>}
            </div>
            {item.stimulus && <p className="whitespace-pre-wrap rounded-lg bg-muted/60 p-3 text-sm" lang={item.language} dir="auto">{item.stimulus}</p>}
            <p className="font-medium" lang={item.language} dir="auto">{item.prompt}</p>
            <ul className="grid gap-1 text-sm sm:grid-cols-2">
              {item.options.map((o, i) => (
                <li key={i} className={cn("flex items-center gap-2 rounded-md border px-2 py-1", o.is_correct && "border-success bg-success/10")} lang={item.language} dir="auto">
                  {o.is_correct && <CheckCircle2 className="size-4 text-success" aria-label={r.correct} />} {o.label}
                </li>
              ))}
            </ul>
            {item.explanation && <p className="text-sm text-muted-foreground">{item.explanation}</p>}
            <div className="flex flex-wrap items-center gap-2">
              <Input className="max-w-sm" placeholder={t.admin.notes} value={notes[item.id] ?? ""} onChange={(e) => setNotes({ ...notes, [item.id]: e.target.value })} aria-label={t.admin.notes} />
              <Button size="sm" onClick={() => decide(item.id, "approved")}>{r.approve}</Button>
              <Button size="sm" variant="outline" onClick={() => decide(item.id, "rejected")}>{r.reject}</Button>
            </div>
          </CardContent>
        </Card>
      ))}
      <Pager meta={list.data.meta} onPage={setPage} />
    </div>
  );
}

function EvaluationQueue() {
  const { t } = useI18n();
  const r = t.admin.review;
  const [reviewed, setReviewed] = useState(false);
  const list = useAdmin<Paged<Evaluation> & { meta: { calibration: Calibration } }>("/review/evaluations", { reviewed: reviewed ? 1 : undefined });
  const qc = useQueryClient();
  const [scores, setScores] = useState<Record<number, { overall: string; notes: string }>>({});

  async function submit(id: number) {
    const s = scores[id];
    try {
      await api(`/admin/review/evaluations/${id}`, { method: "POST", body: { overall: Number(s?.overall), notes: s?.notes || null } });
      toast.success(t.admin.saved);
      qc.invalidateQueries({ queryKey: ["admin"] });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  if (list.error) return <ErrorState error={list.error} onRetry={() => list.refetch()} />;
  if (!list.data) return <Skeleton className="h-96" />;
  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">{r.pseudonymous}</p>
      {list.data.meta.calibration.length > 0 && (
        <Table>
          <thead><tr><th>{r.calibration}</th><th>{r.reviewed}</th><th>{r.mae}</th><th>{r.within}</th></tr></thead>
          <tbody>{list.data.meta.calibration.map((c) => (
            <tr key={c.prompt_version}><td className="font-mono text-xs">{c.prompt_version}</td><td>{c.reviewed}</td><td>{c.mean_absolute_error}</td><td>{Math.round(c.within_half_band * 100)}%</td></tr>
          ))}</tbody>
        </Table>
      )}
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} /> {r.reviewed}
      </label>
      {list.data.data.length === 0 ? <EmptyState title={t.admin.none} /> : list.data.data.map((e) => (
        <Card key={e.id}>
          <CardHeader><CardTitle className="text-sm font-medium text-muted-foreground">{e.language} · {e.prompt_version} · {e.model}</CardTitle></CardHeader>
          <CardContent className="space-y-3">
            <p className="text-sm text-muted-foreground" lang={e.language} dir={e.direction}>{e.task}</p>
            <p className="whitespace-pre-wrap rounded-lg bg-muted/60 p-3" lang={e.language} dir={e.direction}>{e.text}</p>
            <div className="text-sm">
              <p className="font-medium">{r.aiScore}: {e.ai.overall} · {e.ai.level}</p>
              <ul className="mt-1 grid gap-x-4 sm:grid-cols-2">{e.ai.criteria.map((c) => <li key={c.criterion} className="text-muted-foreground">{c.criterion}: {c.score}/5</li>)}</ul>
            </div>
            {e.human ? <p className="text-sm text-success">{r.humanScore}: {e.human.overall} {e.human.notes && `— ${e.human.notes}`}</p> : (
              <form className="flex flex-wrap items-end gap-2" onSubmit={(ev) => { ev.preventDefault(); submit(e.id); }}>
                <Input type="number" min={1} max={5} step={0.5} required className="w-28" aria-label={r.humanScore} placeholder={r.humanScore}
                  value={scores[e.id]?.overall ?? ""} onChange={(ev) => setScores({ ...scores, [e.id]: { overall: ev.target.value, notes: scores[e.id]?.notes ?? "" } })} />
                <Input className="max-w-md flex-1" placeholder={t.admin.notes} aria-label={t.admin.notes}
                  value={scores[e.id]?.notes ?? ""} onChange={(ev) => setScores({ ...scores, [e.id]: { overall: scores[e.id]?.overall ?? "", notes: ev.target.value } })} />
                <Button type="submit" size="sm">{r.submitReview}</Button>
              </form>
            )}
          </CardContent>
        </Card>
      ))}
    </div>
  );
}
