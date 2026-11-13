"use client";

import { useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input, Select } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { api, apiUpload } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import { AdminTitle, Pager, Table, useAdmin, type Paged } from "./admin-shell";

type Summary = { code: string; name: string; courses: number; exercises: number; retired: number; missing_audio: number };
type ExerciseRow = { id: string; key: string; title: string | null; language: string; skill: string; level: string; type: string; status: string; questions: number; has_audio: boolean; attempts: number };

export function AdminContent() {
  const { t, f } = useI18n();
  const c = t.admin.content;
  const qc = useQueryClient();
  const summary = useAdmin<{ data: Summary[] }>("/content/summary");
  const [language, setLanguage] = useState("");
  const [status, setStatus] = useState("");
  const [q, setQ] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const list = useAdmin<Paged<ExerciseRow>>("/content/exercises", { language, status, q: search, page });
  const file = useRef<HTMLInputElement>(null);
  const [dryRun, setDryRun] = useState(true);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<string | null>(null);

  async function setExercise(id: string, next: "published" | "retired") {
    try {
      await api(`/admin/content/exercises/${id}`, { method: "PATCH", body: { status: next } });
      toast.success(t.admin.saved);
      qc.invalidateQueries({ queryKey: ["admin"] });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  async function upload(e: React.FormEvent) {
    e.preventDefault();
    const picked = file.current?.files?.[0];
    if (!picked) return;
    setBusy(true);
    setResult(null);
    try {
      const form = new FormData();
      form.append("pack", picked);
      form.append("dry_run", dryRun ? "1" : "0");
      const { data } = await apiUpload<{ data: { dry_run: boolean; counts: { topics: number; words: number; exercises: number; lessons: number } } }>("/admin/content/import", form);
      setResult(f(c.importOk, data.counts) + (data.dry_run ? "" : ` ${c.imported}`));
      if (!data.dry_run) qc.invalidateQueries({ queryKey: ["admin"] });
    } catch (err) {
      toastError(err, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-6">
      <AdminTitle>{t.admin.nav.content}</AdminTitle>
      <Card>
        <CardHeader><CardTitle className="text-base">{c.summary}</CardTitle></CardHeader>
        <CardContent>
          {summary.error ? <ErrorState error={summary.error} /> : !summary.data ? <Skeleton className="h-40" /> : (
            <Table>
              <thead><tr><th /><th>{c.courses}</th><th>{c.published}</th><th>{c.retired}</th><th>{c.missingAudio}</th></tr></thead>
              <tbody>{summary.data.data.map((s) => (
                <tr key={s.code}><td className="font-medium">{s.name} <span className="text-muted-foreground">({s.code})</span></td><td>{s.courses}</td><td>{s.exercises}</td><td>{s.retired}</td><td>{s.missing_audio}</td></tr>
              ))}</tbody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle className="text-base">{c.import}</CardTitle><CardDescription>{c.importHelp}</CardDescription></CardHeader>
        <CardContent>
          <form onSubmit={upload} className="flex flex-wrap items-center gap-3">
            <input ref={file} type="file" accept="application/json,.json" required className="text-sm" aria-label={c.import} />
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={dryRun} onChange={(e) => setDryRun(e.target.checked)} /> {c.dryRun}</label>
            <Button type="submit" size="sm" loading={busy}>{c.upload}</Button>
          </form>
          {result && <p role="status" className="mt-3 text-sm text-success">{result}</p>}
        </CardContent>
      </Card>

      <section className="space-y-3">
        <h2 className="text-lg font-semibold">{c.exercises}</h2>
        <form className="flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); setSearch(q); setPage(1); }}>
          <Select className="w-auto" value={language} onChange={(e) => { setLanguage(e.target.value); setPage(1); }} aria-label="language">
            <option value="">{t.admin.all}</option>
            {summary.data?.data.map((s) => <option key={s.code} value={s.code}>{s.code}</option>)}
          </Select>
          <Select className="w-auto" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} aria-label="status">
            <option value="">{t.admin.all}</option><option value="published">{c.published}</option><option value="retired">{c.retired}</option>
          </Select>
          <Input className="max-w-xs" placeholder={c.key} value={q} onChange={(e) => setQ(e.target.value)} aria-label={c.key} />
          <Button type="submit" variant="outline">{t.admin.search}</Button>
        </form>
        {list.error ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : !list.data ? <Skeleton className="h-80" /> : list.data.data.length === 0 ? <EmptyState title={t.admin.none} /> : (
          <Table>
            <thead><tr><th>{c.key}</th><th>{c.type}</th><th>{c.questions}</th><th>{c.attempts}</th><th /><th /></tr></thead>
            <tbody>{list.data.data.map((e) => (
              <tr key={e.id}>
                <td><p className="font-mono text-xs">{e.key}</p><p className="text-xs text-muted-foreground">{e.title} · {e.skill} · {e.level}</p></td>
                <td>{e.type}{!e.has_audio && ["listening", "dictation"].includes(e.type) && <Badge variant="warning" className="ms-1">{c.missingAudio}</Badge>}</td>
                <td>{e.questions}</td><td>{e.attempts}</td>
                <td><Badge variant={e.status === "published" ? "success" : "muted"}>{e.status === "published" ? c.published : c.retired}</Badge></td>
                <td className="text-end">
                  <Button size="sm" variant="outline" onClick={() => setExercise(e.id, e.status === "published" ? "retired" : "published")}>
                    {e.status === "published" ? c.retire : c.restore}
                  </Button>
                </td>
              </tr>
            ))}</tbody>
          </Table>
        )}
        <Pager meta={list.data?.meta} onPage={setPage} />
      </section>
    </div>
  );
}
