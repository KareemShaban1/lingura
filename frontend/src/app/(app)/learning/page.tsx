import type { Metadata } from "next";
import { LearningView } from "@/features/plan/learning-view";

export const metadata: Metadata = { title: "My learning" };

export default function LearningPage() {
  return <LearningView />;
}
