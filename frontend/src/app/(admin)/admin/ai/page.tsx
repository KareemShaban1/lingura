import type { Metadata } from "next";
import { AdminAi } from "@/features/admin/ai";

export const metadata: Metadata = { title: "Admin · AI" };

export default function Page() {
  return <AdminAi />;
}
