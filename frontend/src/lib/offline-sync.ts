import { api } from "./api";
import { listOutbox, listPacks, removeFromOutbox } from "./offline-store";

export type SyncResult = { client_ref: string; status: "synced" | "duplicate" | "rejected"; score?: number | null; error?: string };

let running: Promise<SyncResult[]> | null = null;

/**
 * Sends queued offline attempts (per learner profile). Synced, duplicate and rejected attempts leave the
 * outbox; network failures keep them for the next try. Lessons whose offline exercises are all done are
 * submitted for completion too.
 */
export function syncOffline(): Promise<SyncResult[]> {
  running ??= (async () => {
    const outbox = await listOutbox();
    if (!outbox.length || (typeof navigator !== "undefined" && !navigator.onLine)) return [];
    const packs = await listPacks();
    const results: SyncResult[] = [];
    const byProfile = Map.groupBy(outbox, (a) => a.profileId);
    for (const [profileId, attempts] of byProfile) {
      const done = new Set(attempts.map((a) => a.exercise_id));
      const completeLessons = packs
        .filter((p) => p.profileId === profileId && p.pack.exercises.length > 0 && p.pack.exercises.every((e) => done.has(e.id)))
        .map((p) => p.lessonId);
      try {
        const { data } = await api<{ data: SyncResult[] }>(`/learner-profiles/${profileId}/offline-sync`, {
          method: "POST",
          body: {
            attempts: attempts.map(({ client_ref, exercise_id, lesson_id, answers }) => ({ client_ref, exercise_id, lesson_id, answers })),
            complete_lessons: completeLessons,
          },
        });
        for (const r of data) await removeFromOutbox(r.client_ref);
        results.push(...data);
      } catch {
        // Offline or server unavailable: keep the outbox for the next attempt.
      }
    }
    return results;
  })().finally(() => {
    running = null;
  });
  return running;
}
