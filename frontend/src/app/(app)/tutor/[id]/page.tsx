import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { ConversationView } from "@/features/tutor/tutor-views";

export const metadata: Metadata = { title: "Conversation" };

export default function ConversationPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <ConversationView />
    </Suspense>
  );
}
