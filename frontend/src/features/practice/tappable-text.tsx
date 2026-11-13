"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { segmentTokens } from "@/lib/text";
import type { Direction, Word } from "@/lib/types";

/** Mirrors the server's TextNormalizer closely enough to match extracted tokens. */
const normalize = (s: string) => s.normalize("NFC").toLowerCase().replace(/[ً-ٰٟـ]/g, "").replace(/[’ʼ]/g, "'");

/**
 * Reading passage where catalogue words are tappable: shows the meaning in the interface language
 * and lets the learner add the word to their flashcards (automatic word extraction).
 */
export function TappableText({ text, profileId, lang, dir }: { text: string; profileId: string; lang: string; dir: Direction }) {
  const { t } = useI18n();
  const qc = useQueryClient();
  const [open, setOpen] = useState<string | null>(null);
  const [added, setAdded] = useState<Set<number>>(new Set());
  const found = useQuery({
    queryKey: ["extract", profileId, text],
    queryFn: () => api<{ data: Record<string, Word> }>(`/learner-profiles/${profileId}/vocabulary/extract`, { method: "POST", body: { text } }).then((r) => r.data),
    staleTime: Infinity,
  });
  const words = found.data ?? {};
  const tokens = segmentTokens(text, (token) => normalize(token) in words);

  async function add(word: Word) {
    await api(`/learner-profiles/${profileId}/vocabulary`, { method: "POST", body: { word_id: word.id, source: "reading" } });
    setAdded((s) => new Set(s).add(word.id));
    qc.invalidateQueries({ queryKey: ["profile", profileId] });
  }

  return (
    <div lang={lang} dir={dir} className="rounded-xl border bg-card p-5 text-lg leading-loose">
      {tokens.map((token, i) => {
        const word = words[normalize(token)];
        if (!word) return <span key={i}>{token}</span>;
        const inDeck = word.in_deck || added.has(word.id);
        return (
          <span key={i} className="relative inline-block">
            <button
              type="button"
              onClick={() => setOpen(open === `${i}` ? null : `${i}`)}
              aria-expanded={open === `${i}`}
              className="rounded decoration-primary/60 decoration-dotted underline-offset-4 underline hover:bg-secondary"
            >
              {token}
            </button>
            {open === `${i}` && (
              <span role="dialog" dir="auto" className="absolute start-0 top-full z-20 mt-1 block w-60 rounded-lg border bg-card p-3 text-sm leading-normal shadow-lg">
                <span className="block font-semibold" lang={lang}>{word.lemma}{word.pronunciation && <span className="ms-2 font-normal text-muted-foreground">{word.pronunciation}</span>}</span>
                <span className="block text-muted-foreground">{word.meaning}</span>
                <Button size="sm" variant={inDeck ? "ghost" : "secondary"} className="mt-2 w-full" disabled={inDeck} onClick={() => add(word)}>
                  {inDeck ? <><Check /> {t.exercise.inDeck}</> : <><Plus /> {t.exercise.addToDeck}</>}
                </Button>
              </span>
            )}
          </span>
        );
      })}
    </div>
  );
}
