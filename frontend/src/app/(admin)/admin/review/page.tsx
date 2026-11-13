import type { Metadata } from "next";
import { AdminReview } from "@/features/admin/review";

export const metadata: Metadata = { title: "Admin · Review queue" };

export default function Page() {
  return <AdminReview />;
}
