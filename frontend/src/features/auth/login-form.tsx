"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { FieldError, Label } from "@/components/ui/label";
import { keys } from "@/features/queries";
import { api, applyServerErrors } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import type { User } from "@/lib/types";
import { AuthCard } from "./auth-card";
import { useState } from "react";

const schema = z.object({ email: z.email(), password: z.string().min(1) });
type Values = z.infer<typeof schema>;

/** Only same-origin relative paths are allowed as post-login redirects. */
function safeNext(next: string | null) {
  return next && next.startsWith("/") && !next.startsWith("//") ? next : "/home";
}

export function LoginForm() {
  const { t } = useI18n();
  const router = useRouter();
  const params = useSearchParams();
  const qc = useQueryClient();
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<Values>({ resolver: zodResolver(schema) });

  const onSubmit = form.handleSubmit(async (values) => {
    setFormError(null);
    try {
      const { user } = await api<{ user: User }>("/auth/login", { method: "POST", body: values });
      qc.setQueryData(keys.me, user);
      router.replace(user.learner_profiles.length ? safeNext(params.get("next")) : "/onboarding");
    } catch (e) {
      setFormError(applyServerErrors(e, form.setError as never));
    }
  });

  return (
    <AuthCard
      title={t.auth.loginTitle}
      subtitle={t.auth.loginSubtitle}
      footer={
        <>
          {t.auth.noAccount}{" "}
          <Link className="font-medium text-primary hover:underline" href="/register">
            {t.auth.signUp}
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <div className="space-y-2">
          <Label htmlFor="email">{t.auth.email}</Label>
          <Input id="email" type="email" autoComplete="email" aria-invalid={!!form.formState.errors.email} {...form.register("email")} />
          <FieldError message={form.formState.errors.email?.message} />
        </div>
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label htmlFor="password">{t.auth.password}</Label>
            <Link href="/forgot-password" className="text-xs text-primary hover:underline">
              {t.auth.forgot}
            </Link>
          </div>
          <Input id="password" type="password" autoComplete="current-password" {...form.register("password")} />
          <FieldError message={form.formState.errors.password?.message} />
        </div>
        {formError && <FieldError message={formError} />}
        <Button type="submit" className="w-full" loading={form.formState.isSubmitting}>
          {t.auth.signIn}
        </Button>
      </form>
    </AuthCard>
  );
}
