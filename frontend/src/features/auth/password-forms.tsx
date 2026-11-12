"use client";

import { useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { FieldError, Label } from "@/components/ui/label";
import { api, applyServerErrors } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { AuthCard } from "./auth-card";

export function ForgotPasswordForm() {
  const { t } = useI18n();
  const [sent, setSent] = useState<string | null>(null);
  const form = useForm<{ email: string }>({ resolver: zodResolver(z.object({ email: z.email() })) });

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      const res = await api<{ message: string }>("/auth/forgot-password", { method: "POST", body: values });
      setSent(res.message);
    } catch (e) {
      applyServerErrors(e, form.setError as never);
    }
  });

  return (
    <AuthCard title={t.auth.forgotTitle} subtitle={t.auth.forgotSubtitle} footer={<Link className="text-primary hover:underline" href="/login">{t.auth.signIn}</Link>}>
      {sent ? (
        <p role="status" className="rounded-lg bg-success/10 p-3 text-sm text-success">{sent}</p>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          <div className="space-y-2">
            <Label htmlFor="email">{t.auth.email}</Label>
            <Input id="email" type="email" autoComplete="email" {...form.register("email")} />
            <FieldError message={form.formState.errors.email?.message} />
          </div>
          <Button type="submit" className="w-full" loading={form.formState.isSubmitting}>{t.auth.sendLink}</Button>
        </form>
      )}
    </AuthCard>
  );
}

const resetSchema = z
  .object({ password: z.string().min(10), password_confirmation: z.string() })
  .refine((v) => v.password === v.password_confirmation, { path: ["password_confirmation"], message: "Passwords do not match." });

export function ResetPasswordForm() {
  const { t } = useI18n();
  const params = useSearchParams();
  const token = params.get("token");
  const email = params.get("email");
  const [done, setDone] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<z.infer<typeof resetSchema>>({ resolver: zodResolver(resetSchema) });

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await api("/auth/reset-password", { method: "POST", body: { ...values, token, email } });
      setDone(true);
    } catch (e) {
      setFormError(applyServerErrors(e, form.setError as never));
    }
  });

  return (
    <AuthCard title={t.auth.resetTitle} footer={<Link className="text-primary hover:underline" href="/login">{t.auth.signIn}</Link>}>
      {!token || !email ? (
        <p className="text-sm text-destructive">{t.auth.invalidLink}</p>
      ) : done ? (
        <p role="status" className="rounded-lg bg-success/10 p-3 text-sm text-success">{t.auth.resetDone}</p>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          <div className="space-y-2">
            <Label htmlFor="password">{t.auth.password}</Label>
            <Input id="password" type="password" autoComplete="new-password" {...form.register("password")} />
            <p className="text-xs text-muted-foreground">{t.auth.passwordHint}</p>
          </div>
          <div className="space-y-2">
            <Label htmlFor="password_confirmation">{t.auth.passwordConfirm}</Label>
            <Input id="password_confirmation" type="password" autoComplete="new-password" {...form.register("password_confirmation")} />
            <FieldError message={form.formState.errors.password_confirmation?.message ?? form.formState.errors.password?.message} />
          </div>
          {formError && <FieldError message={formError} />}
          <Button type="submit" className="w-full" loading={form.formState.isSubmitting}>{t.auth.resetSubmit}</Button>
        </form>
      )}
    </AuthCard>
  );
}
