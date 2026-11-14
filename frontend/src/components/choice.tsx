"use client";

import { Check } from "lucide-react";
import { cn } from "@/lib/utils";

/** Large selectable tile. Use inside a container with role="radiogroup" (single) or "group" (multi). */
export function Choice({
  selected, onSelect, title, description, aside, multi = false, disabled = false, lang, dir,
}: {
  selected: boolean;
  onSelect: () => void;
  title: React.ReactNode;
  description?: React.ReactNode;
  aside?: React.ReactNode;
  multi?: boolean;
  disabled?: boolean;
  lang?: string;
  dir?: "ltr" | "rtl";
}) {
  return (
    <button
      type="button"
      role={multi ? "checkbox" : "radio"}
      aria-checked={selected}
      disabled={disabled}
      onClick={onSelect}
      className={cn(
        "group flex w-full items-center gap-3 rounded-xl border bg-card p-4 text-start transition-[border-color,box-shadow,background-color] duration-150 hover:border-primary/50 disabled:cursor-not-allowed disabled:opacity-50",
        selected && "border-primary bg-secondary/50 shadow-[0_0_0_1px_var(--primary)]",
      )}
    >
      <div className="min-w-0 flex-1">
        <div className="font-medium" lang={lang} dir={dir}>{title}</div>
        {description && <div className="mt-0.5 text-sm text-muted-foreground">{description}</div>}
        {aside && <div className="mt-2 sm:hidden">{aside}</div>}
      </div>
      {aside && <div className="hidden shrink-0 sm:block">{aside}</div>}
      <span
        className={cn(
          "grid size-5 shrink-0 place-items-center border transition-colors",
          multi ? "rounded-md" : "rounded-full",
          selected ? "border-primary bg-primary text-primary-foreground" : "border-input",
        )}
        aria-hidden
      >
        {selected && <Check className="size-3" strokeWidth={3} />}
      </span>
    </button>
  );
}
