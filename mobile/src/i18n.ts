/** Mobile strings (en/ar). The interface language follows the account setting from the API. */
const en = {
  signIn: "Sign in", email: "Email", password: "Password", signingIn: "Signing in…",
  today: "Today", review: "Review", rewards: "Rewards", profile: "Profile",
  todayEmpty: "Nothing planned for today. Open LinguaForge on the web to adjust your plan.",
  minutes: "min", streak: "{n}-day streak", due: "{n} cards due",
  show: "Show answer", again: "Again", hard: "Hard", good: "Good", easy: "Easy",
  reviewDone: "All caught up — no cards due.", xpTotal: "Total XP", xpWeek: "This week", xpToday: "Today",
  freezes: "{n} streak freezes", achievements: "{n} of {total} achievements",
  signOut: "Sign out", learning: "Learning", webOnly: "Lessons, tutor and exams are available in the web app.",
  error: "Something went wrong", retry: "Try again", done: "Done", openOnWeb: "Do this activity on the web",
};
const ar: typeof en = {
  signIn: "تسجيل الدخول", email: "البريد الإلكتروني", password: "كلمة المرور", signingIn: "جارٍ تسجيل الدخول…",
  today: "اليوم", review: "المراجعة", rewards: "المكافآت", profile: "الملف",
  todayEmpty: "لا شيء مخطط لليوم. افتح LinguaForge على الويب لتعديل خطتك.",
  minutes: "دقيقة", streak: "سلسلة {n} يوم", due: "{n} بطاقة مستحقة",
  show: "أظهر الإجابة", again: "مرة أخرى", hard: "صعب", good: "جيد", easy: "سهل",
  reviewDone: "أنهيت كل شيء — لا بطاقات مستحقة.", xpTotal: "إجمالي النقاط", xpWeek: "هذا الأسبوع", xpToday: "اليوم",
  freezes: "{n} تجميد للسلسلة", achievements: "{n} من {total} إنجازات",
  signOut: "تسجيل الخروج", learning: "تتعلم", webOnly: "الدروس والمعلم والاختبارات متاحة في تطبيق الويب.",
  error: "حدث خطأ ما", retry: "حاول مرة أخرى", done: "تم", openOnWeb: "أنجز هذا النشاط على الويب",
};
export type Strings = typeof en;
export const strings = (locale?: string): Strings => (locale === "ar" ? ar : en);
export const format = (s: string, p: Record<string, string | number>) => s.replace(/\{(\w+)\}/g, (_, k) => String(p[k] ?? ""));
