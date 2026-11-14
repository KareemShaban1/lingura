"use client";

import { useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Plus, Trash2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input, Select } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { api } from "@/lib/api";
import { toastError } from "@/lib/errors";
import { useI18n } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import { AdminTitle, Pager, useAdmin, type Paged } from "./admin-shell";

const TYPES = ["mcq", "cloze", "ordering", "reading", "listening", "dictation", "repeat"] as const;
const SKILLS = ["grammar", "vocabulary", "reading", "listening", "speaking", "writing"];
const LEVELS = ["PRE_A1", "A1", "A2", "B1", "B2", "C1", "C2"];
const CHOICE = ["mcq", "reading", "listening"];
const DEFAULT_SKILL: Record<string, string> = { mcq: "grammar", cloze: "grammar", ordering: "grammar", reading: "reading", listening: "listening", dictation: "listening", repeat: "speaking" };

type Question = { p?: string; o?: string[]; a?: string[]; r?: string; e?: string };
type Objective = { code: string; skill: string; text: { en?: string; ar?: string } };
type Payload = { key: string; type: string; skill: string; level: string; topic?: string; title?: { en?: string; ar?: string }; text?: string; objectives?: Objective[]; questions: Question[] };
type DraftRow = { id: string; key: string; language: string; status: string; type: string | null; level: string | null; title: string | null; author: string | null; reviewer: string | null; review_notes: string | null; is_mine: boolean; can_review: boolean; replaces: string | null; updated_at: string };
type Draft = DraftRow & { payload: Payload; editable: boolean; exercise_id: string | null };

/** One question in the form: `main` is the prompt (or the sentence for repeat), `lines` the options/answers/chunks. */
type QForm = { main: string; lines: string; e: string };
type Form = { language: string; key: string; type: string; skill: string; level: string; topic: string; titleEn: string; titleAr: string; text: string; objCode: string; objEn: string; objAr: string; questions: QForm[] };

const statusVariant = (s: string) => (s === "published" ? "success" : s === "in_review" ? "accent" : s === "rejected" ? "warning" : "muted") as "success" | "accent" | "warning" | "muted";
const split = (s: string) => s.split("\n").map((l) => l.trim()).filter(Boolean);

function toForm(language: string, p?: Payload): Form {
  const o = p?.objectives?.[0];
  return {
    language, key: p?.key ?? "", type: p?.type ?? "mcq", skill: p?.skill ?? "grammar", level: p?.level ?? "A1", topic: p?.topic ?? "",
    titleEn: p?.title?.en ?? "", titleAr: p?.title?.ar ?? "", text: p?.text ?? "",
    objCode: o?.code ?? "", objEn: o?.text.en ?? "", objAr: o?.text.ar ?? "",
    questions: (p?.questions ?? [{}]).map((q) => ({
      main: q.r ?? q.p ?? "", lines: (q.o ?? q.a ?? []).join("\n"), e: q.e ?? "",
    })),
  };
}

function toPayload(f: Form, previous?: Payload): Payload {
  const questions = f.questions.map((q): Question => {
    const base: Question = q.e.trim() ? { e: q.e.trim() } : {};
    if (CHOICE.includes(f.type)) return { p: q.main.trim(), o: split(q.lines), ...base };
    if (f.type === "cloze") return { p: q.main.trim(), a: split(q.lines), ...base };
    if (f.type === "dictation") return { a: split(q.lines), ...base };
    if (f.type === "repeat") return { r: q.main.trim(), ...base };
    return { o: split(q.lines), ...base };
  });
  // The form edits the first objective; any further ones (from packs) are kept as they are.
  const objectives = [
    ...(f.objCode.trim() ? [{ code: f.objCode.trim(), skill: previous?.objectives?.[0]?.skill ?? f.skill, text: { en: f.objEn.trim(), ...(f.objAr.trim() ? { ar: f.objAr.trim() } : {}) } }] : []),
    ...(previous?.objectives?.slice(1) ?? []),
  ];
  return {
    key: f.key.trim(), type: f.type, skill: f.skill, level: f.level, questions,
    ...(f.topic.trim() ? { topic: f.topic.trim() } : {}),
    ...(f.titleEn.trim() ? { title: { en: f.titleEn.trim(), ...(f.titleAr.trim() ? { ar: f.titleAr.trim() } : {}) } } : {}),
    ...(f.text.trim() && ["reading", "listening"].includes(f.type) ? { text: f.text.trim() } : {}),
    ...(objectives.length ? { objectives } : {}),
  };
}

const areaClass = "w-full rounded-lg border border-input bg-card p-2.5 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:opacity-70";

function Field({ label, help, children }: { label: string; help?: string; children: React.ReactNode }) {
  return (
    <label className="block space-y-1 text-sm">
      <span className="font-medium">{label}</span>
      {children}
      {help && <span className="block text-xs text-muted-foreground">{help}</span>}
    </label>
  );
}

function DraftEditor({ draft, languages, onSaved }: { draft: Draft | null; languages: string[]; onSaved: (id: string) => void }) {
  const { t, f: fmt } = useI18n();
  const e = t.admin.editor;
  const qc = useQueryClient();
  const [form, setForm] = useState<Form>(() => toForm(draft?.language ?? languages[0] ?? "en", draft?.payload));
  const [notes, setNotes] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [checked, setChecked] = useState<{ replaces_existing: boolean; needs_audio: boolean } | null>(null);
  const editable = draft ? draft.editable : true;
  const set = (patch: Partial<Form>) => { setForm((cur) => ({ ...cur, ...patch })); setChecked(null); };
  const setQ = (i: number, patch: Partial<QForm>) => set({ questions: form.questions.map((q, n) => (n === i ? { ...q, ...patch } : q)) });

  async function run(action: string, fn: () => Promise<void>) {
    setBusy(action);
    try {
      await fn();
      qc.invalidateQueries({ queryKey: ["admin"] });
    } catch (err) {
      toastError(err, t.common.errorTitle);
    } finally {
      setBusy(null);
    }
  }

  const save = () => run("save", async () => {
    const payload = toPayload(form, draft?.payload);
    const { data } = draft
      ? await api<{ data: Draft }>(`/admin/content/drafts/${draft.id}`, { method: "PUT", body: { payload } })
      : await api<{ data: Draft }>("/admin/content/drafts", { method: "POST", body: { language: form.language, payload } });
    toast.success(t.admin.saved);
    onSaved(data.id);
  });
  const check = () => run("check", async () => {
    if (!draft) return;
    await api(`/admin/content/drafts/${draft.id}`, { method: "PUT", body: { payload: toPayload(form, draft.payload) } });
    const { data } = await api<{ data: { replaces_existing: boolean; needs_audio: boolean } }>(`/admin/content/drafts/${draft.id}/check`, { method: "POST" });
    setChecked(data);
  });
  const submit = () => run("submit", async () => {
    if (!draft) return;
    await api(`/admin/content/drafts/${draft.id}`, { method: "PUT", body: { payload: toPayload(form, draft.payload) } });
    await api(`/admin/content/drafts/${draft.id}/submit`, { method: "POST" });
    toast.success(e.submitted);
  });
  const decide = (decision: "approve" | "reject") => run(decision, async () => {
    if (!draft) return;
    if (decision === "reject" && !notes.trim()) {
      toast.error(e.notesRequired);
      return;
    }
    await api(`/admin/content/drafts/${draft.id}/${decision}`, { method: "POST", body: { notes: notes.trim() || null } });
    toast.success(decision === "approve" ? e.published : e.rejected);
  });

  const choice = CHOICE.includes(form.type);
  const linesHelp = choice ? e.optionsHelp : form.type === "cloze" ? e.answersHelp : form.type === "dictation" ? e.dictationHelp : e.orderingHelp;
  const hasMain = form.type !== "dictation" && form.type !== "ordering";
  const hasLines = form.type !== "repeat";
  const code = form.language;

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <CardTitle className="font-mono text-base">{draft?.key ?? e.newExercise}</CardTitle>
          {draft && <Badge variant={statusVariant(draft.status)}>{e.statuses[draft.status]}</Badge>}
        </div>
        {draft?.author && <CardDescription>{fmt(e.by, { name: draft.author })}</CardDescription>}
      </CardHeader>
      <CardContent className="space-y-5">
        {draft?.status === "rejected" && draft.review_notes && (
          <p role="note" className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm"><span className="font-medium">{e.reviewNotes}:</span> {draft.review_notes}</p>
        )}
        {draft?.replaces && <p className="text-sm font-medium">{fmt(e.replaces, { key: draft.replaces })}</p>}
        {!editable && <p className="text-sm text-muted-foreground">{e.locked}</p>}

        <fieldset disabled={!editable || busy !== null} className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={e.language}>
              <Select value={form.language} disabled={!!draft} onChange={(ev) => set({ language: ev.target.value })}>
                {languages.map((l) => <option key={l} value={l}>{l}</option>)}
              </Select>
            </Field>
            <Field label={e.key} help={fmt(e.keyHelp, { code })}>
              <Input dir="ltr" className="font-mono" value={form.key} onChange={(ev) => set({ key: ev.target.value })} required />
            </Field>
            <Field label={e.type}>
              <Select value={form.type} onChange={(ev) => set({ type: ev.target.value, skill: DEFAULT_SKILL[ev.target.value] })}>
                {TYPES.map((ty) => <option key={ty} value={ty}>{ty}</option>)}
              </Select>
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label={e.skill}>
                <Select value={form.skill} onChange={(ev) => set({ skill: ev.target.value })}>{SKILLS.map((s) => <option key={s} value={s}>{s}</option>)}</Select>
              </Field>
              <Field label={e.level}>
                <Select value={form.level} onChange={(ev) => set({ level: ev.target.value })}>{LEVELS.map((l) => <option key={l} value={l}>{l.replace("_", "-")}</option>)}</Select>
              </Field>
            </div>
            <Field label={e.titleEn}><Input value={form.titleEn} onChange={(ev) => set({ titleEn: ev.target.value })} /></Field>
            <Field label={e.titleAr}><Input dir="rtl" value={form.titleAr} onChange={(ev) => set({ titleAr: ev.target.value })} /></Field>
            <Field label={e.topic}><Input dir="ltr" className="font-mono" value={form.topic} onChange={(ev) => set({ topic: ev.target.value })} /></Field>
          </div>

          {["reading", "listening"].includes(form.type) && (
            <Field label={e.text}><textarea rows={6} dir="auto" className={areaClass} value={form.text} onChange={(ev) => set({ text: ev.target.value })} /></Field>
          )}

          <fieldset className="space-y-3 rounded-lg border p-3">
            <legend className="px-1 text-sm font-medium">{e.objective}</legend>
            <div className="grid gap-3 sm:grid-cols-2">
              <Input dir="ltr" className="font-mono sm:col-span-2" placeholder={fmt(e.objectiveCode, { code })} aria-label={fmt(e.objectiveCode, { code })} value={form.objCode} onChange={(ev) => set({ objCode: ev.target.value })} />
              <Input placeholder={e.objectiveText} aria-label={e.objectiveText} value={form.objEn} onChange={(ev) => set({ objEn: ev.target.value })} />
              <Input dir="rtl" placeholder={e.objectiveTextAr} aria-label={e.objectiveTextAr} value={form.objAr} onChange={(ev) => set({ objAr: ev.target.value })} />
            </div>
          </fieldset>

          <section className="space-y-3">
            <h3 className="text-sm font-semibold">{e.questions}</h3>
            {form.questions.map((q, i) => (
              <div key={i} className="space-y-2 rounded-lg border p-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-medium text-muted-foreground">#{i + 1}</span>
                  {form.questions.length > 1 && (
                    <Button type="button" size="sm" variant="ghost" onClick={() => set({ questions: form.questions.filter((_, n) => n !== i) })}>
                      <Trash2 className="size-4" aria-hidden /> {e.removeQuestion}
                    </Button>
                  )}
                </div>
                {hasMain && (
                  <Field label={form.type === "repeat" ? e.repeatHelp : e.prompt} help={form.type === "cloze" ? e.clozeHelp : undefined}>
                    <Input dir="auto" value={q.main} onChange={(ev) => setQ(i, { main: ev.target.value })} />
                  </Field>
                )}
                {hasLines && (
                  <Field label={linesHelp}>
                    <textarea rows={choice ? 4 : 3} dir="auto" className={areaClass} value={q.lines} onChange={(ev) => setQ(i, { lines: ev.target.value })} />
                  </Field>
                )}
                <Field label={e.explanation}><Input dir="auto" value={q.e} onChange={(ev) => setQ(i, { e: ev.target.value })} /></Field>
              </div>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={() => set({ questions: [...form.questions, { main: "", lines: "", e: "" }] })}>
              <Plus className="size-4" aria-hidden /> {e.addQuestion}
            </Button>
          </section>
        </fieldset>

        {checked && (
          <div role="status" className="space-y-1 text-sm">
            <p className="text-success">{e.checkOk}</p>
            {checked.replaces_existing && <p className="font-medium">{e.replacesExisting}</p>}
            {checked.needs_audio && <p className="text-muted-foreground">{e.needsAudio}</p>}
          </div>
        )}

        {editable && (
          <div className="flex flex-wrap gap-2">
            <Button onClick={save} loading={busy === "save"} disabled={busy !== null}>{e.save}</Button>
            {draft && <Button variant="outline" onClick={check} loading={busy === "check"} disabled={busy !== null}>{e.check}</Button>}
            {draft && <Button variant="secondary" onClick={submit} loading={busy === "submit"} disabled={busy !== null}>{e.submit}</Button>}
          </div>
        )}

        {draft?.status === "in_review" && (draft.can_review ? (
          <div className="space-y-2 rounded-lg border p-3">
            <Field label={e.reviewNotes}><textarea rows={3} className={areaClass} value={notes} onChange={(ev) => setNotes(ev.target.value)} /></Field>
            <div className="flex flex-wrap gap-2">
              <Button onClick={() => decide("approve")} loading={busy === "approve"} disabled={busy !== null}>{e.approve}</Button>
              <Button variant="outline" onClick={() => decide("reject")} loading={busy === "reject"} disabled={busy !== null}>{e.reject}</Button>
            </div>
          </div>
        ) : draft.is_mine && <p className="text-sm text-muted-foreground">{e.ownDraft}</p>)}
      </CardContent>
    </Card>
  );
}

export function AdminEditor() {
  const { t } = useI18n();
  const e = t.admin.editor;
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const selected = params.get("draft");
  const creating = params.get("new") === "1";
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const list = useAdmin<Paged<DraftRow>>("/content/drafts", { status, page });
  const summary = useAdmin<{ data: { code: string }[] }>("/content/summary");
  const detail = useQuery({
    queryKey: ["admin", "draft", selected],
    queryFn: () => api<{ data: Draft }>(`/admin/content/drafts/${selected}`),
    enabled: !!selected,
  });
  const languages = summary.data?.data.map((s) => s.code) ?? [];
  const open = (query: string) => router.replace(`${pathname}?${query}`);

  return (
    <div className="space-y-6">
      <AdminTitle>{e.title}</AdminTitle>
      <p className="max-w-3xl text-sm text-muted-foreground">{e.help}</p>
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <section className="space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <Select className="w-auto" value={status} aria-label={e.drafts} onChange={(ev) => { setStatus(ev.target.value); setPage(1); }}>
              <option value="">{t.admin.all}</option>
              {Object.entries(e.statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </Select>
            <Button size="sm" onClick={() => open("new=1")}><Plus className="size-4" aria-hidden /> {e.newExercise}</Button>
          </div>
          {list.error ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : !list.data ? <Skeleton className="h-60" /> : list.data.data.length === 0 ? <EmptyState title={t.admin.none} /> : (
            <ul className="divide-y overflow-hidden rounded-xl border bg-card">{list.data.data.map((d) => (
              <li key={d.id}>
                <button type="button" onClick={() => open(`draft=${d.id}`)} aria-current={d.id === selected ? "true" : undefined}
                  className={cn("flex w-full items-start justify-between gap-2 px-3 py-2.5 text-start hover:bg-muted/50", d.id === selected && "bg-secondary")}>
                  <span className="min-w-0">
                    <span className="block truncate font-mono text-xs">{d.key}</span>
                    <span className="block text-xs text-muted-foreground">{d.language} · {d.type} · {d.level?.replace("_", "-")}{d.author ? ` · ${d.author}` : ""}</span>
                  </span>
                  <Badge variant={statusVariant(d.status)} className="shrink-0">{e.statuses[d.status]}</Badge>
                </button>
              </li>
            ))}</ul>
          )}
          <Pager meta={list.data?.meta} onPage={setPage} />
        </section>

        <section>
          {creating ? (
            summary.data ? <DraftEditor key="new" draft={null} languages={languages} onSaved={(id) => open(`draft=${id}`)} /> : <Skeleton className="h-96" />
          ) : selected ? (
            detail.error ? <ErrorState error={detail.error} onRetry={() => detail.refetch()} /> : !detail.data || !summary.data ? <Skeleton className="h-96" /> : (
              // Keyed by id and last update so the form restarts from the saved draft after each action.
              <DraftEditor key={`${detail.data.data.id}:${detail.data.data.updated_at}:${detail.data.data.status}`} draft={detail.data.data} languages={languages} onSaved={(id) => open(`draft=${id}`)} />
            )
          ) : <EmptyState title={e.select} />}
        </section>
      </div>
    </div>
  );
}
