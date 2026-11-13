import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { ExamsView } from "@/features/exams/exam-views";

export const metadata: Metadata = { title: "Exam practice" };

export default function Page() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <ExamsView />
    </Suspense>
  );
}
