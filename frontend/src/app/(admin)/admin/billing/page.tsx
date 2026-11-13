import type { Metadata } from "next";
import { AdminBilling } from "@/features/admin/billing";

export const metadata: Metadata = { title: "Admin · Billing" };

export default function Page() {
  return <AdminBilling />;
}
