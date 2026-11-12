import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { TutorHome } from "@/features/tutor/tutor-views";

export const metadata: Metadata = { title: "AI tutor" };

export default function TutorPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <TutorHome />
    </Suspense>
  );
}
