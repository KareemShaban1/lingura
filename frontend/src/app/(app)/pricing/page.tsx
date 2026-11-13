import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { PricingView } from "@/features/billing/billing-views";

export const metadata: Metadata = { title: "Plans" };

export default function PricingPage() {
  return (
    <Suspense fallback={<Skeleton className="h-[32rem]" />}>
      <PricingView />
    </Suspense>
  );
}
