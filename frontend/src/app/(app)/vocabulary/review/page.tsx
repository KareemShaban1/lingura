import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { ReviewSession } from "@/features/vocabulary/vocabulary-views";

export const metadata: Metadata = { title: "Flashcards" };

export default function ReviewPage() {
  return (
    <Suspense fallback={<Skeleton className="h-80" />}>
      <ReviewSession />
    </Suspense>
  );
}
