import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { AssessmentPlayer } from "@/features/assessment/assessment-player";

export const metadata: Metadata = { title: "Placement assessment" };

export default function AssessmentPage() {
  return (
    <Suspense fallback={<Skeleton className="h-80" />}>
      <AssessmentPlayer />
    </Suspense>
  );
}
