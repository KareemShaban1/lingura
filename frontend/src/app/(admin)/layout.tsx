import { Suspense } from "react";
import { FullPageLoader } from "@/features/auth-gate";
import { AdminGate } from "@/features/admin/admin-shell";

export default function AdminLayout({ children }: LayoutProps<"/">) {
  return (
    <Suspense fallback={<FullPageLoader />}>
      <AdminGate>{children}</AdminGate>
    </Suspense>
  );
}
