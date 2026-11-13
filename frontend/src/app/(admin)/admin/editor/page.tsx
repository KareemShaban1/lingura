import type { Metadata } from "next";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui/skeleton";
import { AdminEditor } from "@/features/admin/editor";

export const metadata: Metadata = { title: "Admin · Editor" };

export default function Page() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <AdminEditor />
    </Suspense>
  );
}
