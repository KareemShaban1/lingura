import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { BillingView } from "@/features/billing/billing-views";

export const metadata: Metadata = { title: "Plan & billing" };

export default function BillingPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <BillingView />
    </Suspense>
  );
}
