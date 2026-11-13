import type { Metadata } from "next";
import { OfflineView } from "@/features/offline/offline-views";

export const metadata: Metadata = { title: "Offline lessons" };

/** Outside the authenticated layouts on purpose: it must render with no network and no API. */
export default function OfflinePage() {
  return <OfflineView />;
}
