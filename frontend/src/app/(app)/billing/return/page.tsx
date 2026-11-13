import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { BillingReturnView } from "@/features/billing/billing-views";

export const metadata: Metadata = { title: "Checkout" };

export default function BillingReturnPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64" />}>
      <BillingReturnView />
    </Suspense>
  );
}
