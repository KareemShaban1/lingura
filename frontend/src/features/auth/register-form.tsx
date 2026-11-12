"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input, Select } from "@/components/ui/input";
import { FieldError, Label } from "@/components/ui/label";
import { keys, useCatalog } from "@/features/queries";
import { api, applyServerErrors } from "@/lib/api";
import { useI18n, type Locale } from "@/lib/i18n";
import type { User } from "@/lib/types";
import { useUiStore } from "@/stores/ui-store";
import { AuthCard } from "./auth-card";

const schema = z
  .object({
    name: z.string().min(1).max(120),
    email: z.email(),
    password: z.string().min(10).regex(/[A-Za-z]/).regex(/\d/),
    password_confirmation: z.string(),
    native_language: z.string().optional(),
    interface_language: z.enum(["en", "ar"]),
    accept_terms: z.literal(true),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ["password_confirmation"], message: "Passwords do not match." });
type Values = z.infer<typeof schema>;

export function RegisterForm() {
  const { t, locale } = useI18n();
  const setLocale = useUiStore((s) => s.setLocale);
  const router = useRouter();
  const qc = useQueryClient();
  const catalog = useCatalog();
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { interface_language: locale, native_language: "" },
  });
  const errors = form.formState.errors;

  const onSubmit = form.handleSubmit(async (values) => {
    setFormError(null);
    try {
      const { user } = await api<{ user: User }>("/auth/register", {
        method: "POST",
        body: { ...values, native_language: values.native_language || null, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone },
      });
      qc.setQueryData(keys.me, user);
      router.replace("/onboarding");
    } catch (e) {
      setFormError(applyServerErrors(e, form.setError as never));
    }
  });

  return (
    <AuthCard
      title={t.auth.registerTitle}
      subtitle={t.auth.registerSubtitle}
      footer={
        <>
          {t.auth.haveAccount}{" "}
          <Link className="font-medium text-primary hover:underline" href="/login">
            {t.auth.signIn}
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <div className="space-y-2">
          <Label htmlFor="name">{t.auth.name}</Label>
          <Input id="name" autoComplete="name" aria-invalid={!!errors.name} {...form.register("name")} />
          <FieldError message={errors.name?.message} />
        </div>
        <div className="space-y-2">
          <Label htmlFor="email">{t.auth.email}</Label>
          <Input id="email" type="email" autoComplete="email" aria-invalid={!!errors.email} {...form.register("email")} />
          <FieldError message={errors.email?.message} />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="password">{t.auth.password}</Label>
            <Input id="password" type="password" autoComplete="new-password" aria-invalid={!!errors.password} {...form.register("password")} />
          </div>
          <div className="space-y-2">
            <Label htmlFor="password_confirmation">{t.auth.passwordConfirm}</Label>
            <Input id="password_confirmation" type="password" autoComplete="new-password" {...form.register("password_confirmation")} />
          </div>
        </div>
        <p className="text-xs text-muted-foreground">{t.auth.passwordHint}</p>
        <FieldError message={errors.password?.message ? t.auth.passwordHint : errors.password_confirmation?.message} />
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="native_language">{t.auth.nativeLanguage}</Label>
            <Select id="native_language" {...form.register("native_language")}>
              <option value="">—</option>
              {catalog.data?.languages.map((l) => (
                <option key={l.code} value={l.code}>
                  {l.native_name}
                </option>
              ))}
            </Select>
          </div>
          <div className="space-y-2">
            <Label htmlFor="interface_language">{t.auth.interfaceLanguage}</Label>
            <Select
              id="interface_language"
              {...form.register("interface_language", { onChange: (e) => setLocale(e.target.value as Locale) })}
            >
              <option value="en">English</option>
              <option value="ar">العربية</option>
            </Select>
          </div>
        </div>
        <Controller
          control={form.control}
          name="accept_terms"
          render={({ field }) => (
            <label className="flex items-start gap-3 text-sm">
              <Checkbox checked={field.value === true} onCheckedChange={(v) => field.onChange(v === true)} aria-invalid={!!errors.accept_terms} />
              <span>{t.auth.acceptTerms}</span>
            </label>
          )}
        />
        {errors.accept_terms && <FieldError message={t.auth.acceptTerms} />}
        {formError && <FieldError message={formError} />}
        <Button type="submit" className="w-full" loading={form.formState.isSubmitting}>
          {t.auth.signUp}
        </Button>
      </form>
    </AuthCard>
  );
}
