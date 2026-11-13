import type { Metadata } from "next";
import { AdminContent } from "@/features/admin/content";

export const metadata: Metadata = { title: "Admin · Content" };

export default function Page() {
  return <AdminContent />;
}
