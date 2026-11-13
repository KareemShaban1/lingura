/**
 * Offline lesson packs and the outbox of attempts made offline, kept in IndexedDB on this device only.
 * Cleared on sign-out. Packs contain answer keys for instant feedback; the server re-checks everything on sync.
 */
export type OfflineQuestion = {
  id: number; prompt: string; explanation: string | null;
  options: { id: number; label: string; correct: boolean; position: number | null }[];
  answers: string[];
};
export type OfflineExercise = { id: string; type: "mcq" | "cloze" | "ordering" | "reading"; title: string | null; text: string | null; questions: OfflineQuestion[] };
export type OfflinePack = {
  lesson: {
    id: string; title: string; intro: string | null;
    grammar: { title: string; explanation: string; examples: { sentence: string; translation: string | null }[] } | null;
    words: { lemma: string; pronunciation: string | null; meaning: string | null }[];
  };
  language: { code: string; direction: "ltr" | "rtl" };
  exercises: OfflineExercise[];
  version: string; downloaded_at: string; expires_at: string; skipped_exercises: number;
};
export type StoredPack = { lessonId: string; profileId: string; pack: OfflinePack };
export type OfflineAnswer = { question_id: number; option_id?: number; text?: string; order?: number[] };
export type OutboxAttempt = {
  client_ref: string; profileId: string; lesson_id: string; exercise_id: string; answers: OfflineAnswer[];
  provisional_score: number; finished_at: string;
};

const DB = "linguaforge-offline";
const VERSION = 1;

function open(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB, VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains("packs")) db.createObjectStore("packs", { keyPath: "lessonId" });
      if (!db.objectStoreNames.contains("outbox")) db.createObjectStore("outbox", { keyPath: "client_ref" });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function tx<T>(store: "packs" | "outbox", mode: IDBTransactionMode, run: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await open();
  return new Promise<T>((resolve, reject) => {
    const req = run(db.transaction(store, mode).objectStore(store));
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  }).finally(() => db.close());
}

export const offlineSupported = () => typeof indexedDB !== "undefined";
export const savePack = (p: StoredPack) => tx("packs", "readwrite", (s) => s.put(p));
export const getPack = (lessonId: string) => tx<StoredPack | undefined>("packs", "readonly", (s) => s.get(lessonId));
export const listPacks = () => tx<StoredPack[]>("packs", "readonly", (s) => s.getAll());
export const deletePack = (lessonId: string) => tx("packs", "readwrite", (s) => s.delete(lessonId));
export const queueAttempt = (a: OutboxAttempt) => tx("outbox", "readwrite", (s) => s.put(a));
export const listOutbox = () => tx<OutboxAttempt[]>("outbox", "readonly", (s) => s.getAll());
export const removeFromOutbox = (ref: string) => tx("outbox", "readwrite", (s) => s.delete(ref));

/** Removes everything this device stored offline (called on sign-out). */
export function clearOffline(): Promise<void> {
  if (!offlineSupported()) return Promise.resolve();
  return new Promise((resolve) => {
    const req = indexedDB.deleteDatabase(DB);
    req.onsuccess = req.onerror = req.onblocked = () => resolve();
  });
}

/** Mirrors the server's normalisation closely enough for provisional feedback. */
export const normalizeAnswer = (s: string) =>
  s.normalize("NFC").toLowerCase().replace(/[ً-ٰٟـ]/g, "").replace(/[’ʼ]/g, "'")
    .replace(/[^\p{L}\p{M}\p{N}' ]+/gu, " ").replace(/\s+/g, " ").trim();

/** Provisional, on-device check. The authoritative score comes from the server on sync. */
export function checkLocally(e: OfflineExercise, q: OfflineQuestion, a: OfflineAnswer): boolean {
  if (e.type === "cloze") return q.answers.some((x) => normalizeAnswer(x) === normalizeAnswer(a.text ?? ""));
  if (e.type === "ordering") {
    const expected = [...q.options].sort((x, y) => (x.position ?? 0) - (y.position ?? 0)).map((o) => o.id);
    return JSON.stringify(expected) === JSON.stringify(a.order ?? []);
  }
  return q.options.find((o) => o.id === a.option_id)?.correct ?? false;
}
