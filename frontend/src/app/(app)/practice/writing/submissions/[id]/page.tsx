import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { SubmissionView } from "@/features/writing/writing-views";

export const metadata: Metadata = { title: "Writing feedback" };

export default function Page() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <SubmissionView />
    </Suspense>
  );
}
