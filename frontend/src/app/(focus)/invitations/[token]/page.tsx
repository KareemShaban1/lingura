import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { InvitationView } from "@/features/family/family-views";

export const metadata: Metadata = { title: "Invitation" };

export default function InvitationPage() {
  return (
    <Suspense fallback={<Skeleton className="h-56" />}>
      <InvitationView />
    </Suspense>
  );
}
