import type { Metadata } from "next";
import { VocabularyView } from "@/features/vocabulary/vocabulary-views";

export const metadata: Metadata = { title: "Vocabulary" };

export default function VocabularyPage() {
  return <VocabularyView />;
}
