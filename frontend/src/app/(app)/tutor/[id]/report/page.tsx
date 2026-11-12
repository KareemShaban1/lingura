import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { ConversationReportView } from "@/features/tutor/report-view";

export const metadata: Metadata = { title: "Conversation report" };

export default function ConversationReportPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <ConversationReportView />
    </Suspense>
  );
}
