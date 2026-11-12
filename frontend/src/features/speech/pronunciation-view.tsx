"use client";

import { useI18n } from "@/lib/i18n";
import type { PronunciationFeedback } from "@/lib/types";
import { cn } from "@/lib/utils";

const tone: Record<string, string> = {
  correct: "bg-success/15 text-success",
  substituted: "bg-warning/25 text-foreground",
  omitted: "bg-destructive/10 text-destructive line-through",
  inserted: "bg-muted text-muted-foreground",
};

/** Word-by-word intelligibility result. Colour is always paired with a text legend and tooltips. */
export function PronunciationView({ result, lang, dir }: { result: PronunciationFeedback; lang: string; dir: "ltr" | "rtl" }) {
  const { t, f } = useI18n();
  return (
    <div className="space-y-3">
      <p className="text-sm font-medium">{f(t.speech.understood, { pct: Math.round(result.accuracy * 100) })}</p>
      <p lang={lang} dir={dir} className="flex flex-wrap gap-1.5 text-lg">
        {result.words.map((w, i) => (
          <span key={i} title={`${t.speech.legend[w.status]}${w.heard && w.status === "substituted" ? ` → ${w.heard}` : ""}`}
            className={cn("rounded-md px-1.5 py-0.5", tone[w.status])}>
            {w.expected ?? w.heard}
            {w.status === "substituted" && <span className="ms-1 text-xs opacity-70">({w.heard})</span>}
          </span>
        ))}
      </p>
      <ul className="flex flex-wrap gap-3 text-xs text-muted-foreground">
        {(["correct", "substituted", "omitted"] as const).map((k) => (
          <li key={k} className="flex items-center gap-1"><span className={cn("size-2.5 rounded-sm", tone[k])} />{t.speech.legend[k]}</li>
        ))}
      </ul>
      <p className="text-xs text-muted-foreground">{t.speech.method}</p>
    </div>
  );
}
