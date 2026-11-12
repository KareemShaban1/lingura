import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { ResultsView } from "@/features/assessment/results-view";

export const metadata: Metadata = { title: "Assessment results" };

export default function ResultsPage() {
  return (
    <Suspense fallback={<Skeleton className="h-80" />}>
      <ResultsView />
    </Suspense>
  );
}
