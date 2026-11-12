"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";
import type {
  Course, ExerciseAttempt, ExerciseSummary, GrammarTopic, GrammarTopicDetail, LessonDetail, UserWord, VocabularySummary, Word,
  AssessmentAttempt, AssessmentItem, AssessmentReport, Dashboard, LearningPlan, LearningSession,
  OnboardingCatalog, PlanItem, PlanVersion, Progress, User,
} from "@/lib/types";
import { useUiStore } from "@/stores/ui-store";

export const keys = {
  me: ["me"] as const,
  catalog: ["catalog"] as const,
  dashboard: (id: string) => ["profile", id, "dashboard"] as const,
  plan: (id: string) => ["profile", id, "plan"] as const,
  planItems: (id: string) => ["profile", id, "plan-items"] as const,
  planVersions: (id: string) => ["profile", id, "plan-versions"] as const,
  progress: (id: string) => ["profile", id, "progress"] as const,
  attempt: (id: string) => ["attempt", id] as const,
  attemptNext: (id: string) => ["attempt", id, "next"] as const,
  report: (id: string) => ["attempt", id, "report"] as const,
  session: (id: string) => ["session", id] as const,
  exports: ["exports"] as const,
};

export function useMe() {
  return useQuery({ queryKey: keys.me, queryFn: () => api<{ data: User }>("/auth/me").then((r) => r.data), staleTime: 60_000 });
}

/** The learner profile currently in focus (persisted choice, falling back to the most recent one). */
export function useActiveProfile() {
  const { data: user } = useMe();
  const activeId = useUiStore((s) => s.activeProfileId);
  const profiles = user?.learner_profiles ?? [];
  return profiles.find((p) => p.id === activeId) ?? profiles[0] ?? null;
}

export function useCatalog() {
  return useQuery({ queryKey: keys.catalog, queryFn: () => api<OnboardingCatalog>("/catalog/onboarding"), staleTime: 10 * 60_000 });
}

export function useDashboard(profileId?: string) {
  return useQuery({
    queryKey: keys.dashboard(profileId ?? ""),
    queryFn: () => api<{ data: Dashboard }>(`/learner-profiles/${profileId}/dashboard`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function usePlan(profileId?: string) {
  return useQuery({
    queryKey: keys.plan(profileId ?? ""),
    queryFn: () => api<{ data: LearningPlan | null }>(`/learner-profiles/${profileId}/plan`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function usePlanItems(profileId?: string) {
  return useQuery({
    queryKey: keys.planItems(profileId ?? ""),
    queryFn: () => api<{ data: PlanItem[] }>(`/learner-profiles/${profileId}/plan/items`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function usePlanVersions(profileId?: string) {
  return useQuery({
    queryKey: keys.planVersions(profileId ?? ""),
    queryFn: () => api<{ data: PlanVersion[] }>(`/learner-profiles/${profileId}/plan/versions`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function useProgress(profileId?: string) {
  return useQuery({
    queryKey: keys.progress(profileId ?? ""),
    queryFn: () => api<{ data: Progress }>(`/learner-profiles/${profileId}/progress`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function useAttempt(attemptId: string) {
  return useQuery({ queryKey: keys.attempt(attemptId), queryFn: () => api<{ data: AssessmentAttempt }>(`/assessment-attempts/${attemptId}`).then((r) => r.data) });
}

export function useNextItem(attemptId: string, enabled = true) {
  return useQuery({
    queryKey: keys.attemptNext(attemptId),
    queryFn: () => api<{ done: boolean; item: AssessmentItem | null; attempt: AssessmentAttempt }>(`/assessment-attempts/${attemptId}/next`),
    enabled,
    staleTime: Infinity,
  });
}

export function useReport(attemptId: string) {
  return useQuery({ queryKey: keys.report(attemptId), queryFn: () => api<{ data: AssessmentReport }>(`/assessment-attempts/${attemptId}/report`).then((r) => r.data) });
}

export function useLearningSession(sessionId: string) {
  return useQuery({ queryKey: keys.session(sessionId), queryFn: () => api<{ data: LearningSession }>(`/learning-sessions/${sessionId}`).then((r) => r.data) });
}

/** Invalidate everything derived from a learner profile after a mutation. */
export function useInvalidateProfile() {
  const qc = useQueryClient();
  return (profileId: string) => {
    qc.invalidateQueries({ queryKey: ["profile", profileId] });
    qc.invalidateQueries({ queryKey: keys.me });
  };
}

export function useStartAssessment() {
  return useMutation({
    mutationFn: (profileId: string) => api<{ data: AssessmentAttempt }>(`/learner-profiles/${profileId}/assessments`, { method: "POST" }).then((r) => r.data),
  });
}

export function usePlanAction(profileId: string) {
  const invalidate = useInvalidateProfile();
  return useMutation({
    mutationFn: (action: "pause" | "resume" | "regenerate") => api<{ data: LearningPlan }>(`/learner-profiles/${profileId}/plan/${action}`, { method: "POST" }),
    onSuccess: () => invalidate(profileId),
  });
}

// ---------------------------------------------------------------- Phase 2

export const keys2 = {
  courses: (p: string) => ["profile", p, "courses"] as const,
  lesson: (p: string, l: string) => ["profile", p, "lesson", l] as const,
  exercises: (p: string, skill?: string) => ["profile", p, "exercises", skill ?? "all"] as const,
  attempt: (a: string) => ["exercise-attempt", a] as const,
  grammar: (p: string) => ["profile", p, "grammar"] as const,
  grammarTopic: (p: string, slug: string) => ["profile", p, "grammar", slug] as const,
  vocab: (p: string, filter: string, collection?: number | null) => ["profile", p, "vocabulary", filter, collection ?? 0] as const,
  vocabSummary: (p: string) => ["profile", p, "vocabulary-summary"] as const,
  vocabQueue: (p: string) => ["profile", p, "vocabulary-queue"] as const,
  collections: (p: string) => ["profile", p, "collections"] as const,
};

export function useCourses(profileId?: string) {
  return useQuery({
    queryKey: keys2.courses(profileId ?? ""),
    queryFn: () => api<{ data: Course[]; next_lesson_id: string | null }>(`/learner-profiles/${profileId}/courses`),
    enabled: !!profileId,
  });
}

export function useLesson(profileId: string | undefined, lessonId: string) {
  return useQuery({
    queryKey: keys2.lesson(profileId ?? "", lessonId),
    queryFn: () => api<{ data: LessonDetail }>(`/learner-profiles/${profileId}/lessons/${lessonId}`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function useExercises(profileId?: string, skill?: string) {
  return useQuery({
    queryKey: keys2.exercises(profileId ?? "", skill),
    queryFn: () => api<{ data: ExerciseSummary[] }>(`/learner-profiles/${profileId}/exercises${skill ? `?skill=${skill}` : ""}`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function useGrammarTopics(profileId?: string) {
  return useQuery({
    queryKey: keys2.grammar(profileId ?? ""),
    queryFn: () => api<{ data: GrammarTopic[] }>(`/learner-profiles/${profileId}/grammar-topics`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function useGrammarTopic(profileId: string | undefined, slug: string) {
  return useQuery({
    queryKey: keys2.grammarTopic(profileId ?? "", slug),
    queryFn: () => api<{ data: GrammarTopicDetail }>(`/learner-profiles/${profileId}/grammar-topics/${slug}`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function useVocabulary(profileId: string | undefined, filter: string, collection?: number | null) {
  return useQuery({
    queryKey: keys2.vocab(profileId ?? "", filter, collection),
    queryFn: () =>
      api<{ data: UserWord[] }>(`/learner-profiles/${profileId}/vocabulary?filter=${filter}${collection ? `&collection=${collection}` : ""}`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function useVocabularySummary(profileId?: string) {
  return useQuery({
    queryKey: keys2.vocabSummary(profileId ?? ""),
    queryFn: () => api<{ data: VocabularySummary }>(`/learner-profiles/${profileId}/vocabulary/summary`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export function useCollections(profileId?: string) {
  return useQuery({
    queryKey: keys2.collections(profileId ?? ""),
    queryFn: () => api<{ data: { id: number; name: string; items_count: number }[] }>(`/learner-profiles/${profileId}/vocabulary-collections`).then((r) => r.data),
    enabled: !!profileId,
  });
}

export async function lookupWords(profileId: string, q: string) {
  return api<{ data: Word[] }>(`/learner-profiles/${profileId}/vocabulary/lookup?q=${encodeURIComponent(q)}`).then((r) => r.data);
}

export async function startExerciseAttempt(profileId: string, exerciseId: string, opts: { lessonId?: string | null; sessionId?: string | null }) {
  return api<{ data: ExerciseAttempt }>(`/learner-profiles/${profileId}/exercises/${exerciseId}/attempts`, {
    method: "POST",
    body: { lesson_id: opts.lessonId ?? null, session_id: opts.sessionId ?? null },
  }).then((r) => r.data);
}
