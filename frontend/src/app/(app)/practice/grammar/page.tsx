import type { Metadata } from "next";
import { GrammarListView } from "@/features/grammar/grammar-views";

export const metadata: Metadata = { title: "Grammar" };

export default function GrammarPage() {
  return <GrammarListView />;
}
