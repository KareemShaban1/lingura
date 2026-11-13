import { Suspense } from "react";
import type { Metadata } from "next";
import { Skeleton } from "@/components/ui/skeleton";
import { NotificationsView } from "@/features/notifications/notifications";

export const metadata: Metadata = { title: "Notifications" };

export default function NotificationsPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64" />}>
      <NotificationsView />
    </Suspense>
  );
}
