import type { Metadata } from "next";
import { AdminLanguages } from "@/features/admin/system";

export const metadata: Metadata = { title: "Admin · Languages" };

export default function Page() {
  return <AdminLanguages />;
}
