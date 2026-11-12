import type { Metadata } from "next";
import { WritingListView } from "@/features/writing/writing-views";

export const metadata: Metadata = { title: "Writing" };

export default function WritingPage() {
  return <WritingListView />;
}
