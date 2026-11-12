import { Suspense } from "react";
import { AppGate, FullPageLoader } from "@/features/auth-gate";

export default function AppLayout({ children }: LayoutProps<"/">) {
  return (
    <Suspense fallback={<FullPageLoader />}>
      <AppGate>{children}</AppGate>
    </Suspense>
  );
}
