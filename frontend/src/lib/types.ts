export type SkillSlug = "listening" | "speaking" | "reading" | "writing" | "grammar" | "vocabulary";
export type Direction = "ltr" | "rtl";
export type CapabilityStatus = "supported" | "beta" | "unsupported";

export interface Level {
  code: string;
  label: string;
  ordinal: number;
}

export interface Language {
  code: string;
  name: string;
  native_name: string;
  direction: Direction;
  script: string;
  is_target_enabled: boolean;
  is_interface_enabled: boolean;
  variants?: { code: string; name: string; is_default: boolean }[];
  capabilities: Record<string, { status: CapabilityStatus; quality_tier: number }>;
}

export interface OnboardingCatalog {
  languages: Language[];
  purposes: { slug: string; name: string; description: string | null }[];
  skills: { slug: SkillSlug; name: string; is_productive: boolean }[];
  levels: Level[];
  daily_minutes: { min: number; max: number; suggested: number[] };
}

export interface LearnerProfile {
  id: string;
  status: "assessment_pending" | "active" | "archived";
  target_language: { code: string; name: string; native_name: string; direction: Direction };
  variant: { code: string; name: string } | null;
  purpose: { slug: string; name: string };
  self_reported_level: Level | null;
  target_level: Level | null;
  daily_minutes: number;
  focus_skills: SkillSlug[];
  schedule: { day: number; time: string }[];
  preferences: Record<string, string>;
  onboarded_at: string | null;
  last_studied_at: string | null;
}

export interface User {
  id: number;
  name: string;
  email: string;
  email_verified: boolean;
  roles: string[];
  is_staff: boolean;
  profile: {
    native_language: string | null;
    interface_language: "en" | "ar";
    interface_direction: Direction;
    timezone: string;
    country_code: string | null;
  };
  preferences: {
    theme: "light" | "dark" | "system";
    daily_reminder_enabled: boolean;
    reminder_time: string | null;
    marketing_opt_in: boolean;
  };
  learner_profiles: LearnerProfile[];
  features: string[];
}

export type SectionStatus = "pending" | "in_progress" | "completed" | "skipped" | "not_available";

export interface AssessmentAttempt {
  id: string;
  status: "in_progress" | "completed" | "abandoned";
  engine_version: string;
  learner_profile_id: string;
  target_language: { code: string; name: string; direction: Direction };
  started_at: string;
  completed_at: string | null;
  overall_level: Level | null;
  overall_is_provisional: boolean;
  sections: { skill: SkillSlug; status: SectionStatus; items_answered: number; max_items: number; min_items: number; reason: string | null }[];
}

export interface AssessmentItem {
  id: string;
  skill: SkillSlug;
  format: "mcq" | "cloze_mcq" | "reading_mcq" | "listening_mcq" | "true_false";
  stimulus: string | null;
  has_audio: boolean;
  prompt: string;
  options: { id: number; label: string }[];
}

export interface AssessmentReport {
  attempt_id: string;
  status: string;
  engine_version: string;
  completed_at: string | null;
  is_official: false;
  disclaimer: string;
  overall: { level: Level | null; is_provisional: boolean; provisional_reasons: string[]; method: string };
  skills: {
    skill: SkillSlug;
    status: SectionStatus;
    reason: string | null;
    level: Level | null;
    confidence: "low" | "medium" | "high" | null;
    scaled_score: number | null;
    standard_error: number | null;
    items_answered: number;
    items_correct: number;
  }[];
  findings: { type: "strength" | "weakness" | "recommendation"; code: string; skill: SkillSlug | null; detail: string | null; detail_label: string | null }[];
}

export interface PlanItem {
  id: string;
  scheduled_date: string;
  position: number;
  skill: SkillSlug | null;
  activity_type: string;
  title: string;
  objective: string;
  target_level: Level | null;
  estimated_minutes: number;
  reason_code: string;
  reason: string;
  topic: string | null;
  lesson_id: string | null;
  exercise_id: string | null;
  status: "pending" | "in_progress" | "completed" | "skipped";
  completed_at: string | null;
}

export interface PlanVersion {
  version_number: number;
  generator: string;
  trigger: string;
  daily_minutes: number;
  valid_from: string;
  valid_until: string;
  rationale: string;
  created_at: string;
  allocations?: { skill: SkillSlug; minutes_per_week: number; priority_score: number; reason_code: string; reason: string }[];
  items_completed?: number;
  items_total?: number;
}

export interface LearningPlan {
  id: string;
  status: "active" | "paused" | "archived";
  paused_at: string | null;
  current_version: PlanVersion | null;
}

export interface SkillProfile {
  skill: SkillSlug;
  status: "assessed" | "not_assessed";
  level: Level | null;
  confidence: "low" | "medium" | "high" | null;
  scaled_score: number | null;
  source: string;
  assessed_at: string | null;
  history?: { recorded_at: string; scaled_score: number; level: string; standard_error: number; evidence_count: number }[];
}

export interface Dashboard {
  profile: LearnerProfile;
  today: string;
  plan_status: "active" | "paused" | null;
  today_items: PlanItem[];
  today_minutes_planned: number;
  today_minutes_done: number;
  week: { date: string; minutes: number }[];
  week_minutes: number;
  streak: { current_days: number; studied_today: boolean };
  skills: SkillProfile[];
  latest_attempt: AssessmentAttempt | null;
  vocabulary_due: number;
}

export interface Progress extends Partial<ProgressPhase2> {
  skills: SkillProfile[];
  weekly_activity: { week_start: string; minutes: number; active_days: number }[];
  practice_by_skill: Record<string, { minutes: number; sessions: number }>;
  streak: { current_days: number; studied_today: boolean };
  total_minutes: number;
  attempts: { id: string; completed_at: string; overall_level: string | null; overall_is_provisional: boolean; skills: Record<string, string | null> }[];
}

export interface LearningSession {
  id: string;
  started_at: string;
  ended_at: string | null;
  active_seconds: number;
  outcome: string | null;
  self_rating: number | null;
  learner_profile_id: string;
  target_language: { code: string; name: string };
  plan_item: PlanItem | null;
}

// ---------------------------------------------------------------- Phase 2
export type ExerciseType = "mcq" | "cloze" | "ordering" | "reading" | "listening" | "dictation";

export interface ExerciseSummary {
  id: string;
  type: ExerciseType;
  skill: SkillSlug;
  level: Level;
  title: string | null;
  grammar_topic: string | null;
  questions_count: number;
  estimated_minutes: number;
  available: boolean;
  last_score?: number | null;
  completed?: boolean;
  score?: number | null;
}

export interface AnswerFeedback {
  question_id: number;
  is_correct: boolean;
  score: number;
  feedback_code: "check_accents" | "word_accuracy" | "empty" | "invalid_order" | null;
  given: string | null;
  selected_option_id: number | null;
  correct_option_id: number | null;
  correct_answer: string | null;
  explanation: string | null;
}

export interface ExerciseAttempt {
  id: string;
  status: "in_progress" | "completed";
  score: number | null;
  questions_total: number;
  questions_answered: number;
  words_per_minute: number | null;
  lesson_id: string | null;
  started_at: string;
  completed_at: string | null;
  exercise: ExerciseSummary & {
    language: { code: string; direction: Direction };
    objectives: string[];
    stimulus: string | null;
    has_audio: boolean;
    questions: { id: number; prompt: string; has_audio: boolean; options: { id: number; label: string }[]; answer: AnswerFeedback | null }[];
  };
}

export interface Word {
  id: number;
  lemma: string;
  part_of_speech: string;
  gender: string | null;
  level: string | null;
  meaning: string | null;
  examples: { sentence: string; translation: string | null }[];
  synonyms: string[];
  antonyms: string[];
  in_deck?: boolean;
}

export interface UserWord {
  id: number;
  word: Word;
  source: string;
  state: "new" | "learning" | "review" | "relearning";
  mastered: boolean;
  stability_days: number | null;
  reps: number;
  lapses: number;
  due_at: string;
  is_due: boolean;
  last_reviewed_at: string | null;
  collections?: { id: number; name: string }[];
}

export interface VocabularySummary {
  total: number;
  due_now: number;
  new: number;
  learning: number;
  review: number;
  mastered: number;
  retention_30d: number | null;
  reviews_30d: number;
}

export interface LessonSummary {
  id: string;
  title: string;
  skill: SkillSlug;
  level: Level;
  estimated_minutes: number;
  status: "not_started" | "in_progress" | "completed";
  best_score: number | null;
}

export interface Course {
  id: string;
  title: string;
  description: string | null;
  level_from: Level;
  level_to: Level;
  units: { title: string; lessons: LessonSummary[] }[];
  lessons_total: number;
  lessons_completed: number;
}

export interface GrammarTopic {
  slug: string;
  title: string;
  level: Level;
  exercises_count: number | null;
  mastery: { value: number | null; enough_evidence: boolean; answers_total: number; answers_correct: number; last_practiced_at: string | null } | null;
  explanation?: string;
  examples?: { sentence: string; translation: string | null }[];
}

export interface LessonDetail extends LessonSummary {
  intro: string;
  language: { code: string; direction: Direction };
  objectives: string[];
  grammar_topic: GrammarTopic | null;
  words: Word[];
  exercises: ExerciseSummary[];
}

export interface GrammarTopicDetail extends GrammarTopic {
  language: { code: string; direction: Direction };
  exercises: ExerciseSummary[];
  recent_mistakes: { prompt: string; given: string | null; correct: string | null; explanation: string | null; answered_at: string }[];
}

export interface ProgressPhase2 {
  skill_accuracy: Record<string, { answered: number; correct: number; accuracy: number | null }>;
  daily_skill_activity: ({ date: string } & Partial<Record<SkillSlug, number>>)[];
  vocabulary: VocabularySummary;
  grammar: { topics_practised: number; topics_mastered: number };
  reading: { recent_wpm: number[] };
  exercises_completed: number;
}
