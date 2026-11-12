import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { WritingTaskView } from "@/features/writing/writing-views";

export const metadata: Metadata = { title: "Writing task" };

export default function WritingTaskPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <WritingTaskView />
    </Suspense>
  );
}
