import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { FamilyView } from "@/features/family/family-views";

export const metadata: Metadata = { title: "Family group" };

export default function FamilyPage() {
  return (
    <Suspense fallback={<Skeleton className="h-80" />}>
      <FamilyView />
    </Suspense>
  );
}
