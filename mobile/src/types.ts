export type LearnerProfile = { id: string; target_language: { code: string; native_name: string; direction: "ltr" | "rtl" } };
export type User = { id: number; name: string; email: string; profile: { interface_language: string }; learner_profiles: LearnerProfile[]; features: string[] };
export type PlanItem = { id: string; title: string; activity_type: string; estimated_minutes: number; status: string; skill: string | null };
export type Dashboard = { today_items: PlanItem[]; streak: { current_days: number; studied_today: boolean }; vocabulary_due?: number; today_minutes_done: number; today_minutes_planned: number };
export type Card = { id: number; word: { lemma: string; pronunciation?: string | null; meaning: string | null; part_of_speech: string; examples: { sentence: string; translation: string | null }[] } };
export type Gamification = { xp_total: number; xp_today: number; xp_week: number; streak: { current_days: number; longest_days: number; freezes_available: number; active_today: boolean }; achievements_unlocked: number; achievements_total: number };
