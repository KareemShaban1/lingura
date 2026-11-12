import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { SessionView } from "@/features/plan/session-view";

export const metadata: Metadata = { title: "Learning session" };

export default function SessionPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <SessionView />
    </Suspense>
  );
}
