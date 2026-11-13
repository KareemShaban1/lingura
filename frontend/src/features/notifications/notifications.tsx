"use client";

import Link from "next/link";
import { useQueryClient } from "@tanstack/react-query";
import { Bell } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { useDate } from "@/features/billing/billing-views";
import { useAchievements, useNotifications } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import type { AppNotification } from "@/lib/types";
import { cn } from "@/lib/utils";

/** Renders a notification in the reader's language from its machine-readable kind + data. */
function useNotificationText() {
  const { t, f } = useI18n();
  const achievements = useAchievements();
  const titles = new Map((achievements.data ?? []).map((a) => [a.slug, a.title]));
  return (n: AppNotification) => {
    const d = n.data;
    const plan = typeof d.plan === "string" ? (t.billing.tiers[d.plan.split("_")[0]] ?? d.plan) : "";
    const template = t.notifications.kinds[d.kind];
    if (!template) return d.kind;
    return f(template, {
      plan,
      date: typeof d.date === "string" ? d.date : "",
      name: String(d.name ?? ""),
      group: String(d.group ?? ""),
      achievement: titles.get(String(d.achievement)) ?? String(d.achievement ?? "").replaceAll("_", " "),
    });
  };
}

export function NotificationBell() {
  const { t } = useI18n();
  const { data } = useNotifications();
  const unread = data?.meta.unread ?? 0;
  return (
    <Button asChild variant="ghost" size="icon" aria-label={unread ? `${t.nav.notifications} (${unread})` : t.nav.notifications}>
      <Link href="/notifications" className="relative">
        <Bell />
        {unread > 0 && <span className="absolute end-1.5 top-1.5 grid min-w-4 place-items-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-4 text-destructive-foreground">{unread > 9 ? "9+" : unread}</span>}
      </Link>
    </Button>
  );
}

export function NotificationsView() {
  const { t } = useI18n();
  const list = useNotifications();
  const qc = useQueryClient();
  const text = useNotificationText();
  const date = useDate();

  if (list.error) return <ErrorState error={list.error} onRetry={() => list.refetch()} />;
  if (!list.data) return <Skeleton className="h-64" />;

  async function markRead() {
    await api("/me/notifications/read", { method: "POST" }).catch(() => undefined);
    qc.invalidateQueries({ queryKey: ["notifications"] });
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-3">
        <h1 className="font-display text-3xl md:text-4xl">{t.notifications.title}</h1>
        {list.data.meta.unread > 0 && <Button variant="outline" size="sm" onClick={markRead}>{t.notifications.markRead}</Button>}
      </div>
      {list.data.data.length === 0 ? <EmptyState title={t.notifications.empty} /> : (
        <Card>
          <CardContent className="divide-y p-0">
            {list.data.data.map((n) => (
              <div key={n.id} className={cn("flex items-start gap-3 px-4 py-3", !n.read && "bg-secondary/40")}>
                <span className={cn("mt-2 size-2 shrink-0 rounded-full", n.read ? "bg-transparent" : "bg-primary")} aria-hidden />
                <div className="flex-1">
                  <p className="text-sm">{text(n)}</p>
                  <p className="mt-0.5 text-xs text-muted-foreground">{date(n.created_at)}</p>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
