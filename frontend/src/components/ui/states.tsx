"use client";

import { AlertTriangle, Inbox } from "lucide-react";
import { Button } from "./button";
import { useI18n } from "@/lib/i18n";
import { cn } from "@/lib/utils";

export function ErrorState({ error, onRetry, className }: { error: unknown; onRetry?: () => void; className?: string }) {
  const { t } = useI18n();
  const message = error instanceof Error ? error.message : String(error);
  return (
    <div role="alert" className={cn("flex flex-col items-center gap-3 rounded-xl border border-dashed p-8 text-center", className)}>
      <AlertTriangle className="size-6 text-destructive" aria-hidden />
      <div>
        <p className="font-medium">{t.common.errorTitle}</p>
        <p className="mt-1 text-sm text-muted-foreground">{message}</p>
      </div>
      {onRetry && (
        <Button variant="outline" size="sm" onClick={onRetry}>
          {t.common.retry}
        </Button>
      )}
    </div>
  );
}

export function EmptyState({ title, body, action, className }: { title: string; body?: string; action?: React.ReactNode; className?: string }) {
  return (
    <div className={cn("flex flex-col items-center gap-3 rounded-xl border border-dashed p-8 text-center", className)}>
      <Inbox className="size-6 text-muted-foreground" aria-hidden />
      <div>
        <p className="font-medium">{title}</p>
        {body && <p className="mt-1 max-w-sm text-sm text-muted-foreground">{body}</p>}
      </div>
      {action}
    </div>
  );
}
