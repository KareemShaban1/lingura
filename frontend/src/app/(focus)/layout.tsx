import { Suspense } from "react";
import { FocusGate, FullPageLoader } from "@/features/auth-gate";

export default function FocusLayout({ children }: LayoutProps<"/">) {
  return (
    <Suspense fallback={<FullPageLoader />}>
      <FocusGate>{children}</FocusGate>
    </Suspense>
  );
}
