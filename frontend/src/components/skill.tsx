import { BookOpen, Ear, Languages, MessageCircle, PenLine, Puzzle, type LucideIcon } from "lucide-react";
import type { SkillSlug } from "@/lib/types";
import { cn } from "@/lib/utils";

export const SKILLS: SkillSlug[] = ["listening", "reading", "speaking", "writing", "grammar", "vocabulary"];

export const skillIcon: Record<SkillSlug, LucideIcon> = {
  listening: Ear,
  speaking: MessageCircle,
  reading: BookOpen,
  writing: PenLine,
  grammar: Puzzle,
  vocabulary: Languages,
};

/** Literal class names so Tailwind can see them. */
export const skillText: Record<SkillSlug, string> = {
  listening: "text-skill-listening",
  speaking: "text-skill-speaking",
  reading: "text-skill-reading",
  writing: "text-skill-writing",
  grammar: "text-skill-grammar",
  vocabulary: "text-skill-vocabulary",
};
export const skillBg: Record<SkillSlug, string> = {
  listening: "bg-skill-listening",
  speaking: "bg-skill-speaking",
  reading: "bg-skill-reading",
  writing: "bg-skill-writing",
  grammar: "bg-skill-grammar",
  vocabulary: "bg-skill-vocabulary",
};
export const skillVar: Record<SkillSlug, string> = {
  listening: "var(--skill-listening)",
  speaking: "var(--skill-speaking)",
  reading: "var(--skill-reading)",
  writing: "var(--skill-writing)",
  grammar: "var(--skill-grammar)",
  vocabulary: "var(--skill-vocabulary)",
};

export function SkillIcon({ skill, className }: { skill: SkillSlug; className?: string }) {
  const Icon = skillIcon[skill];
  return (
    <span className={cn("inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted", skillText[skill], className)}>
      <Icon className="size-[18px]" aria-hidden />
    </span>
  );
}

/** Seven-step CEFR ladder: filled up to the learner's level. Readable without colour (label is always shown). */
export function LevelLadder({ ordinal, skill, className }: { ordinal: number | null; skill: SkillSlug; className?: string }) {
  return (
    <div className={cn("flex gap-1", className)} aria-hidden>
      {Array.from({ length: 7 }, (_, i) => (
        <span key={i} className={cn("h-1.5 flex-1 rounded-full", ordinal !== null && i <= ordinal ? skillBg[skill] : "bg-muted")} />
      ))}
    </div>
  );
}
