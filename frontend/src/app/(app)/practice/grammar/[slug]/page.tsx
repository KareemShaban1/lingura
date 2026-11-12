import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { GrammarTopicView } from "@/features/grammar/grammar-views";

export const metadata: Metadata = { title: "Grammar topic" };

export default function GrammarTopicPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <GrammarTopicView />
    </Suspense>
  );
}
