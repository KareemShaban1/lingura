import { Suspense } from "react";
import type { Metadata } from "next";
import { LoginForm } from "@/features/auth/login-form";
import { FullPageLoader } from "@/features/auth-gate";

export const metadata: Metadata = { title: "Sign in" };

export default function LoginPage() {
  return (
    <Suspense fallback={<FullPageLoader />}>
      <LoginForm />
    </Suspense>
  );
}
