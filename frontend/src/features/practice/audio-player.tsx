"use client";

import { useEffect, useRef, useState } from "react";
import { Pause, Play } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { cn } from "@/lib/utils";

const SPEEDS = [0.75, 1, 1.25];

/** Plays private audio through short-lived signed URLs, with playback-speed control. */
export function AudioPlayer({ src, compact = false }: { src: string; compact?: boolean }) {
  const { t } = useI18n();
  const audio = useRef<HTMLAudioElement | null>(null);
  const [playing, setPlaying] = useState(false);
  const [loading, setLoading] = useState(false);
  const [speed, setSpeed] = useState(1);

  useEffect(() => () => audio.current?.pause(), []);
  useEffect(() => {
    if (audio.current) audio.current.playbackRate = speed;
  }, [speed]);

  async function toggle() {
    if (playing && audio.current) {
      audio.current.pause();
      return;
    }
    setLoading(true);
    try {
      const { url } = await api<{ url: string }>(src); // fresh signed URL each play
      audio.current ??= new Audio();
      audio.current.src = url;
      audio.current.playbackRate = speed;
      audio.current.onplay = () => setPlaying(true);
      audio.current.onpause = audio.current.onended = () => setPlaying(false);
      await audio.current.play();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t.common.errorTitle);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className={cn("flex flex-wrap items-center gap-3", !compact && "rounded-xl border bg-secondary/40 p-4")}>
      <Button variant={compact ? "outline" : "default"} onClick={toggle} loading={loading}>
        {playing ? <Pause /> : <Play className="rtl:rotate-180" />} {t.exercise.play}
      </Button>
      <div role="radiogroup" aria-label={t.exercise.speed} className="flex items-center gap-1 text-xs">
        <span className="me-1 text-muted-foreground">{t.exercise.speed}</span>
        {SPEEDS.map((s) => (
          <button key={s} role="radio" aria-checked={speed === s} onClick={() => setSpeed(s)}
            className={cn("rounded-md border px-2 py-1 font-mono", speed === s ? "border-primary bg-secondary" : "bg-card hover:bg-muted")}>
            {s}×
          </button>
        ))}
      </div>
    </div>
  );
}
