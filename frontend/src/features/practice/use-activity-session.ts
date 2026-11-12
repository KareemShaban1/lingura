"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
import type { LearningSession } from "@/lib/types";

/**
 * Every study activity runs inside a learning session so time counts towards streaks and analytics.
 * Uses the plan-item session passed in the URL, or creates a standalone one. The server caps credited
 * time at real elapsed time, so the client value is only an upper bound.
 */
export function useActivitySession(profileId: string | undefined, activity: "lesson" | "exercise" | "vocabulary_review", existing: string | null, enabled = true) {
  const storageKey = () => `lf-session:${activity}:${window.location.pathname}`;
  // Resume the session created earlier for this page (e.g. after a refresh) instead of opening a new one.
  const [sessionId, setSessionId] = useState<string | null>(() =>
    existing ?? (typeof window !== "undefined" ? sessionStorage.getItem(storageKey()) : null),
  );
  const startedAt = useRef<number>(0);
  const creating = useRef(false);

  useEffect(() => {
    startedAt.current = Date.now();
  }, []);

  useEffect(() => {
    if (!enabled || existing || sessionId || !profileId || creating.current) return;
    creating.current = true;
    const key = storageKey();
    api<{ data: LearningSession }>(`/learner-profiles/${profileId}/learning-sessions`, { method: "POST", body: { activity_type: activity } })
      .then(({ data }) => {
        sessionStorage.setItem(key, data.id);
        setSessionId(data.id);
      })
      .catch(() => undefined); // time tracking must never block learning
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, existing, profileId, activity]);

  const complete = useCallback(async () => {
    if (!sessionId) return;
    const seconds = Math.max(1, Math.round((Date.now() - startedAt.current) / 1000));
    await api(`/learning-sessions/${sessionId}/complete`, { method: "POST", body: { active_seconds: seconds } }).catch(() => undefined);
    Object.keys(sessionStorage).filter((k) => sessionStorage.getItem(k) === sessionId).forEach((k) => sessionStorage.removeItem(k));
  }, [sessionId]);

  return { sessionId, complete };
}
