import { Suspense } from "react";
import type { Metadata } from "next";
import { ResetPasswordForm } from "@/features/auth/password-forms";
import { FullPageLoader } from "@/features/auth-gate";

export const metadata: Metadata = { title: "Choose a new password" };

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={<FullPageLoader />}>
      <ResetPasswordForm />
    </Suspense>
  );
}
