import type { Metadata } from "next";
import { AdminAudit } from "@/features/admin/system";

export const metadata: Metadata = { title: "Admin · Audit log" };

export default function Page() {
  return <AdminAudit />;
}
