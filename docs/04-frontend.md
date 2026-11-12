# 04 — Frontend Routes & Components

Next.js (App Router) + TypeScript, Tailwind, shadcn/ui (Radix primitives), TanStack Query (server state), Zustand (UI/session state: active target language, assessment player state), React Hook Form + Zod, Recharts, PWA (manifest + service worker for shell caching; no offline writes in P1).

## Route map (✅ Phase 1)

```
app/
├─ (marketing)/
│  └─ page.tsx                         ✅ Landing
├─ (auth)/
│  ├─ login/page.tsx                   ✅
│  ├─ register/page.tsx                ✅
│  └─ forgot-password/page.tsx         ✅
├─ (app)/                              ✅ authenticated shell (sidebar ≥ md, bottom tab bar < md)
│  ├─ onboarding/page.tsx              ✅ 6-step wizard (language → variant → purpose → level → time & schedule → focus)
│  ├─ home/page.tsx                    ✅ Learning dashboard
│  ├─ assessment/[attemptId]/page.tsx  ✅ Adaptive placement player
│  ├─ assessment/[attemptId]/results/page.tsx ✅ Results & report
│  ├─ learning/page.tsx                ✅ My Learning: roadmap (week view), plan versions, pause/resume/regenerate
│  ├─ learning/session/[sessionId]/page.tsx ✅ Session runner (objective + timer + complete)
│  ├─ progress/page.tsx                ✅ Skill levels & assessment history (real data only)
│  ├─ profile/page.tsx                 ✅ Account, languages, preferences, data export, delete account
│  ├─ learning/courses/page.tsx        ✅ P2 course tree with progress
│  ├─ lessons/[lessonId]/page.tsx      ✅ P2 lesson player (objectives, grammar, words, exercises, completion)
│  ├─ practice/page.tsx                ✅ P2 exercise library by skill
│  ├─ practice/[exerciseId]/page.tsx   ✅ P2 exercise player (mcq, cloze, ordering, reading w/ tap-to-translate, listening w/ speed, dictation)
│  ├─ practice/grammar/…               ✅ P2 grammar topics, mastery, explanations, recent mistakes
│  ├─ vocabulary/page.tsx              ✅ P2 deck, dictionary search, collections, retention
│  ├─ vocabulary/review/page.tsx       ✅ P2 FSRS flashcard session
│  ├─ tutor/page.tsx                   ✅ P3 tutor & scenario picker, mode (text / push-to-talk / realtime voice)
│  ├─ tutor/[id]/page.tsx              ✅ P3 conversation (corrections, TTS playback, recorder, realtime WebRTC)
│  ├─ tutor/[id]/report/page.tsx       ✅ P3 conversation report
│  ├─ practice/writing/…               ✅ P3 writing tasks, editor with word count, evaluation with annotated text
└─ admin/…                             P4
```

Navigation items are driven by a `features` list returned from `/auth/me`; items for unreleased modules are **not rendered** (no dead buttons).

## Component architecture
```
src/
├─ app/                      routes (thin: compose feature components)
├─ components/ui/            shadcn primitives (button, card, input, dialog, progress, …)
├─ components/layout/        AppShell, SideNav, BottomNav, LanguageSwitcher, ThemeToggle
├─ features/
│  ├─ auth/                  LoginForm, RegisterForm, useMe, auth api
│  ├─ onboarding/            OnboardingWizard, steps/*, schema.ts (Zod)
│  ├─ assessment/            AssessmentPlayer, ItemRenderer, SectionProgress, ResultsReport, SkillLevelBar
│  ├─ plan/                  RoadmapWeek, PlanItemCard, PlanVersionHistory, PlanControls
│  ├─ dashboard/             TodayCard, SkillSnapshot, WeeklyMinutesChart
│  ├─ profile/               PreferencesForm, DangerZone
│  ├─ speech/                Recorder (MediaRecorder, level meter, limits), PronunciationView
│  ├─ tutor/                 TutorHome, ConversationView, RealtimeVoice, ConversationReportView
│  └─ writing/               WritingListView, WritingTaskView, SubmissionResult, AnnotatedText
├─ lib/                      api client (fetch + CSRF), query keys, i18n, cn()
├─ stores/                   useActiveProfileStore (Zustand, persisted)
└─ types/                    API types (hand-written to match Laravel Resources; OpenAPI-generated in P2)
```

## UX principles applied
- **Direction:** `<html dir>` follows interface language; all spacing uses logical properties (`ps-*`, `me-*`), icons that imply direction are mirrored.
- **Target-language text** is rendered with its own `dir`/`lang` attributes (an Arabic item inside an English UI renders RTL correctly).
- **States:** every query renders skeleton → content | empty state with a real next action | error with retry.
- **Theme:** light/dark via `class` strategy, tokens in CSS variables; contrast ≥ 4.5:1.
- **Motion:** 150–200 ms transitions, `prefers-reduced-motion` respected.
