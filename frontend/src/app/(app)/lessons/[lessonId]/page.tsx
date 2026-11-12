import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { LessonView } from "@/features/courses/course-views";

export const metadata: Metadata = { title: "Lesson" };

export default function LessonPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <LessonView />
    </Suspense>
  );
}
