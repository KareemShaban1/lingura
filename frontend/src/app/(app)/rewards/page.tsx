import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { RewardsView } from "@/features/rewards/rewards-views";

export const metadata: Metadata = { title: "Rewards" };

export default function RewardsPage() {
  return (
    <Suspense fallback={<Skeleton className="h-[40rem]" />}>
      <RewardsView />
    </Suspense>
  );
}
