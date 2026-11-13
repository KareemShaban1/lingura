"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, Layers, Plus, Search, Trash2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { useActivitySession } from "@/features/practice/use-activity-session";
import { keys2, lookupWords, useActiveProfile, useCollections, useInvalidateProfile, useVocabulary, useVocabularySummary } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import type { LearnerProfile, UserWord, Word } from "@/lib/types";
import { cn } from "@/lib/utils";
import { toastError } from "@/lib/errors";

function DictionarySearch({ profile }: { profile: LearnerProfile }) {
  const { t } = useI18n();
  const invalidate = useInvalidateProfile();
  const [q, setQ] = useState("");
  const [term, setTerm] = useState("");
  const [added, setAdded] = useState<Set<number>>(new Set());
  useEffect(() => {
    const id = setTimeout(() => setTerm(q.trim()), 250);
    return () => clearTimeout(id);
  }, [q]);
  const results = useQuery({ queryKey: ["lookup", profile.id, term], queryFn: () => lookupWords(profile.id, term), enabled: term.length > 0 });

  async function add(w: Word) {
    try {
      await api(`/learner-profiles/${profile.id}/vocabulary`, { method: "POST", body: { word_id: w.id, source: "manual" } });
      setAdded((s) => new Set(s).add(w.id));
      invalidate(profile.id);
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  return (
    <div className="relative">
      <Search className="pointer-events-none absolute start-3 top-3 size-4 text-muted-foreground" aria-hidden />
      <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t.vocab.search} aria-label={t.vocab.search}
        className="h-10 ps-9" lang={profile.target_language.code} dir="auto" />
      {term && results.data && (
        <ul className="absolute inset-x-0 top-full z-20 mt-1 max-h-80 overflow-auto rounded-xl border bg-card p-1 shadow-lg">
          {results.data.length === 0 && <li className="p-3 text-sm text-muted-foreground">—</li>}
          {results.data.map((w) => (
            <li key={w.id} className="flex items-center gap-3 rounded-lg p-2 hover:bg-muted">
              <div className="min-w-0 flex-1">
                <p className="font-medium" lang={profile.target_language.code} dir={profile.target_language.direction}>{w.lemma}{w.pronunciation && <span className="ms-2 text-sm font-normal text-muted-foreground">{w.pronunciation}</span>}</p>
                <p className="truncate text-xs text-muted-foreground">{w.meaning}</p>
              </div>
              <Button size="sm" variant={added.has(w.id) ? "ghost" : "secondary"} disabled={added.has(w.id)} onClick={() => add(w)}>
                {added.has(w.id) ? <><CheckCircle2 /> {t.vocab.added}</> : <><Plus /> {t.vocab.add}</>}
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function WordRow({ card, profile, collections, onChanged }: { card: UserWord; profile: LearnerProfile; collections: { id: number; name: string }[]; onChanged: () => void }) {
  const { t, f, locale } = useI18n();
  async function toggle(collectionId: number) {
    await api(`/vocabulary-collections/${collectionId}/items`, { method: "POST", body: { user_vocabulary_id: card.id } });
    onChanged();
  }
  async function remove() {
    await api(`/user-vocabulary/${card.id}`, { method: "DELETE" });
    onChanged();
  }
  return (
    <li className="flex flex-wrap items-center gap-3 border-b py-3 last:border-0">
      <div className="min-w-0 flex-1">
        <p className="flex items-center gap-2">
          <span className="text-lg font-medium" lang={profile.target_language.code} dir={profile.target_language.direction}>{card.word.lemma}</span>
          {card.word.pronunciation && <span className="text-sm text-muted-foreground">{card.word.pronunciation}</span>}
          {card.mastered ? <Badge variant="success">{t.vocab.mastered}</Badge> : <Badge variant="muted">{t.vocab.states[card.state]}</Badge>}
        </p>
        <p className="text-sm text-muted-foreground">{card.word.meaning}</p>
        <p className="text-xs text-muted-foreground">
          {card.is_due ? t.vocab.due : f(t.vocab.nextReview, { date: new Date(card.due_at).toLocaleDateString(locale === "ar" ? "ar" : "en", { day: "numeric", month: "short" }) })}
        </p>
      </div>
      <div className="flex flex-wrap items-center gap-1">
        {collections.map((c) => {
          const on = card.collections?.some((x) => x.id === c.id);
          return (
            <button key={c.id} onClick={() => toggle(c.id)} aria-pressed={on}
              className={cn("rounded-full border px-2.5 py-0.5 text-xs", on ? "border-primary bg-secondary" : "text-muted-foreground hover:bg-muted")}>{c.name}</button>
          );
        })}
        <Button variant="ghost" size="icon" aria-label={t.vocab.remove} onClick={remove}><Trash2 /></Button>
      </div>
    </li>
  );
}

export function VocabularyView() {
  const { t, f } = useI18n();
  const profile = useActiveProfile();
  const qc = useQueryClient();
  const [filter, setFilter] = useState<"all" | "due" | "learning" | "mastered">("all");
  const [collection, setCollection] = useState<number | null>(null);
  const [newName, setNewName] = useState("");
  const summary = useVocabularySummary(profile?.id);
  const words = useVocabulary(profile?.id, filter, collection);
  const collections = useCollections(profile?.id);

  if (!profile) return <Skeleton className="h-96" />;
  const refresh = () => qc.invalidateQueries({ queryKey: ["profile", profile.id] });
  const s = summary.data;

  async function createCollection() {
    if (!newName.trim()) return;
    try {
      await api(`/learner-profiles/${profile!.id}/vocabulary-collections`, { method: "POST", body: { name: newName.trim() } });
      setNewName("");
      qc.invalidateQueries({ queryKey: keys2.collections(profile!.id) });
    } catch (e) {
      toastError(e, t.common.errorTitle);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <h1 className="font-display text-3xl md:text-4xl">{t.vocab.title}</h1>
        {s && s.due_now > 0 ? (
          <Button asChild size="lg"><Link href="/vocabulary/review"><Layers /> {f(t.vocab.review, { n: s.due_now })}</Link></Button>
        ) : <p className="text-sm text-muted-foreground">{t.vocab.noDue}</p>}
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {([["total", s?.total], ["due", s?.due_now], ["mastered", s?.mastered]] as const).map(([k, v]) => (
          <Card key={k}><CardContent className="p-4"><p className="text-xs text-muted-foreground">{t.vocab[k]}</p><p className="font-display text-3xl">{v ?? "–"}</p></CardContent></Card>
        ))}
        <Card><CardContent className="p-4"><p className="text-xs text-muted-foreground">{t.vocab.retention}</p>
          <p className="font-display text-3xl">{s?.retention_30d != null ? `${Math.round(s.retention_30d * 100)}%` : "–"}</p>
          {s && s.retention_30d == null && <p className="text-[11px] text-muted-foreground">{t.vocab.retentionNone}</p>}
        </CardContent></Card>
      </div>

      <DictionarySearch profile={profile} />

      <div className="flex flex-wrap items-center gap-2">
        {(["all", "due", "learning", "mastered"] as const).map((k) => (
          <button key={k} onClick={() => setFilter(k)} aria-pressed={filter === k}
            className={cn("rounded-full border px-3 py-1 text-sm", filter === k ? "border-primary bg-primary text-primary-foreground" : "bg-card hover:bg-muted")}>{t.vocab.filters[k]}</button>
        ))}
        <span className="mx-1 h-5 w-px bg-border" />
        {collections.data?.map((c) => (
          <button key={c.id} onClick={() => setCollection(collection === c.id ? null : c.id)} aria-pressed={collection === c.id}
            className={cn("rounded-full border px-3 py-1 text-sm", collection === c.id ? "border-primary bg-secondary" : "bg-card hover:bg-muted")}>{c.name} <span className="text-muted-foreground">{c.items_count}</span></button>
        ))}
        <form className="flex items-center gap-1" onSubmit={(e) => { e.preventDefault(); createCollection(); }}>
          <Input value={newName} onChange={(e) => setNewName(e.target.value)} placeholder={t.vocab.newCollection} className="h-8 w-36 text-xs" aria-label={t.vocab.newCollection} />
          <Button type="submit" size="sm" variant="outline" disabled={!newName.trim()}>{t.vocab.create}</Button>
        </form>
      </div>

      <Card>
        <CardContent className="px-5 py-2">
          {words.error ? <ErrorState error={words.error} onRetry={() => words.refetch()} className="my-4" />
            : !words.data ? <Skeleton className="my-4 h-40" />
            : words.data.length === 0 ? <EmptyState title={t.vocab.empty} className="my-4 border-0" />
            : <ul>{words.data.map((c) => <WordRow key={c.id} card={c} profile={profile} collections={collections.data ?? []} onChanged={refresh} />)}</ul>}
        </CardContent>
      </Card>
    </div>
  );
}

/** Event-handler clock read (kept outside the component body). */
const msSince = (start: number) => Date.now() - start;

export function ReviewSession() {
  const { t, f } = useI18n();
  const profile = useActiveProfile();
  const planSession = useSearchParams().get("session");
  const session = useActivitySession(profile?.id, "vocabulary_review", planSession);
  const invalidate = useInvalidateProfile();
  const queue = useQuery({
    queryKey: keys2.vocabQueue(profile?.id ?? ""),
    queryFn: () => api<{ data: UserWord[] }>(`/learner-profiles/${profile!.id}/vocabulary/review-queue?limit=30`).then((r) => r.data),
    enabled: !!profile,
    staleTime: Infinity,
    refetchOnMount: "always",
  });
  const [index, setIndex] = useState(0);
  const [revealed, setRevealed] = useState(false);
  const [reviewed, setReviewed] = useState(0);
  const [done, setDone] = useState(false);
  const shownAt = useRef(0);
  useEffect(() => {
    shownAt.current = Date.now();
  }, [index]);

  const cards = queue.data ?? [];
  const card = cards[index];

  async function rate(rating: 1 | 2 | 3 | 4) {
    if (!card) return;
    try {
      await api(`/user-vocabulary/${card.id}/reviews`, { method: "POST", body: { rating, duration_ms: msSince(shownAt.current) } });
    } catch (e) {
      toastError(e, t.common.errorTitle);
      return;
    }
    setReviewed((n) => n + 1);
    setRevealed(false);
    if (index + 1 >= cards.length) {
      await session.complete();
      invalidate(profile!.id);
      setDone(true);
    } else {
      setIndex(index + 1);
    }
  }

  if (queue.error) return <ErrorState error={queue.error} onRetry={() => queue.refetch()} />;
  if (!queue.data || !profile) return <Skeleton className="mx-auto h-80 max-w-lg" />;

  if (done || cards.length === 0) {
    return (
      <div className="mx-auto max-w-lg py-10 text-center">
        <CheckCircle2 className="mx-auto size-12 text-success" aria-hidden />
        {/* Decided by this session's work, not the (refetched) queue, which is empty once everything is reviewed. */}
        <h1 className="mt-4 font-display text-3xl">{reviewed > 0 ? t.vocab.sessionDone : t.vocab.noDue}</h1>
        {reviewed > 0 && <p className="mt-2 text-muted-foreground">{f(t.vocab.sessionSummary, { n: reviewed })}</p>}
        <div className="mt-8 flex justify-center gap-3">
          <Button asChild><Link href={planSession ? "/learning" : "/vocabulary"}>{planSession ? t.exercise.backToPlan : t.vocab.title}</Link></Button>
        </div>
      </div>
    );
  }

  const lang = profile.target_language;
  return (
    <div className="mx-auto max-w-lg space-y-6">
      <div className="flex items-center justify-between text-sm text-muted-foreground">
        <span>{t.vocab.sessionTitle}</span>
        <span>{f(t.vocab.remaining, { n: cards.length - index })}</span>
      </div>
      <Card className="min-h-72">
        <CardContent className="flex min-h-72 flex-col items-center justify-center gap-4 p-8 text-center">
          <p lang={lang.code} dir={lang.direction} className="font-display text-5xl">{card.word.lemma}</p>
          {card.word.pronunciation && <p className="text-lg text-muted-foreground">{card.word.pronunciation}</p>}
          <p className="text-xs uppercase tracking-wider text-muted-foreground">{card.word.part_of_speech}{card.word.gender ? ` · ${card.word.gender}` : ""}</p>
          {revealed && (
            <div className="w-full space-y-3 border-t pt-4 motion-safe:animate-[fadeIn_200ms_ease-out]">
              <p className="text-xl font-medium">{card.word.meaning}</p>
              {card.word.examples[0] && (
                <div className="rounded-lg bg-muted p-3 text-sm">
                  <p lang={lang.code} dir={lang.direction}>{card.word.examples[0].sentence}</p>
                  {card.word.examples[0].translation && <p dir="auto" className="text-muted-foreground">{card.word.examples[0].translation}</p>}
                </div>
              )}
              {(card.word.synonyms.length > 0 || card.word.antonyms.length > 0) && (
                <p className="text-xs text-muted-foreground">
                  {card.word.synonyms.length > 0 && <>{t.vocab.synonyms}: <span lang={lang.code}>{card.word.synonyms.join(", ")}</span> </>}
                  {card.word.antonyms.length > 0 && <>{t.vocab.antonyms}: <span lang={lang.code}>{card.word.antonyms.join(", ")}</span></>}
                </p>
              )}
            </div>
          )}
        </CardContent>
      </Card>
      {revealed ? (
        <div className="grid grid-cols-4 gap-2">
          {([1, 2, 3, 4] as const).map((r) => (
            <Button key={r} variant={r === 1 ? "outline" : r === 3 ? "default" : "secondary"} className={cn(r === 1 && "text-destructive")} onClick={() => rate(r)}>
              {t.vocab.ratings[r]}
            </Button>
          ))}
        </div>
      ) : (
        <Button size="lg" className="w-full" onClick={() => setRevealed(true)}>{t.vocab.reveal}</Button>
      )}
    </div>
  );
}
