import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { ExercisePlayer } from "@/features/practice/exercise-player";

export const metadata: Metadata = { title: "Exercise" };

export default function ExercisePage() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <ExercisePlayer />
    </Suspense>
  );
}
