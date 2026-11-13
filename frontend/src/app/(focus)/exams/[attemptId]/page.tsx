import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { ExamPlayer } from "@/features/exams/exam-views";

export const metadata: Metadata = { title: "Practice test" };

export default function Page() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <ExamPlayer />
    </Suspense>
  );
}
