import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { ExamResults } from "@/features/exams/exam-views";

export const metadata: Metadata = { title: "Practice test results" };

export default function Page() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <ExamResults />
    </Suspense>
  );
}
