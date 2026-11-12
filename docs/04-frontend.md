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
│  ├─ practice/…  vocabulary/…  tutor/…    P2/P3 (nav items hidden until module flag enabled)
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
│  └─ profile/               PreferencesForm, DangerZone
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
