"use client";

import { useEffect, useRef, useState } from "react";
import { Mic, Square } from "lucide-react";
import { Button } from "@/components/ui/button";
import { apiUpload } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { cn } from "@/lib/utils";

const MAX_SECONDS = 120;

/** Records audio with MediaRecorder and uploads it as a private recording; returns its id via onRecorded. */
export function Recorder({
  profileId, purpose, onRecorded, disabled = false, compact = false,
}: {
  profileId: string;
  purpose: "conversation" | "pronunciation" | "speaking_assessment";
  onRecorded: (recordingId: string, durationMs: number) => void | Promise<void>;
  disabled?: boolean;
  compact?: boolean;
}) {
  const { t, f } = useI18n();
  const [state, setState] = useState<"idle" | "recording" | "uploading">("idle");
  const [seconds, setSeconds] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const recorder = useRef<MediaRecorder | null>(null);
  const chunks = useRef<Blob[]>([]);
  const startedAt = useRef(0);
  const timer = useRef<number | null>(null);

  useEffect(() => () => {
    if (timer.current) window.clearInterval(timer.current);
    recorder.current?.stream.getTracks().forEach((tr) => tr.stop());
  }, []);

  async function start() {
    setError(null);
    if (typeof window === "undefined" || !navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === "undefined") {
      setError(t.recorder.unsupported);
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const mime = ["audio/webm;codecs=opus", "audio/webm", "audio/mp4", "audio/ogg"].find((m) => MediaRecorder.isTypeSupported(m));
      const rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
      chunks.current = [];
      rec.ondataavailable = (e) => e.data.size && chunks.current.push(e.data);
      rec.onstop = () => upload(rec.mimeType);
      recorder.current = rec;
      rec.start();
      startedAt.current = Date.now();
      setSeconds(0);
      setState("recording");
      timer.current = window.setInterval(() => {
        const s = Math.floor((Date.now() - startedAt.current) / 1000);
        setSeconds(s);
        if (s >= MAX_SECONDS) stop();
      }, 250);
    } catch {
      setError(t.recorder.noMic);
    }
  }

  function stop() {
    if (timer.current) window.clearInterval(timer.current);
    if (recorder.current?.state === "recording") recorder.current.stop();
    recorder.current?.stream.getTracks().forEach((tr) => tr.stop());
  }

  async function upload(mimeType: string) {
    const durationMs = Date.now() - startedAt.current;
    setState("uploading");
    try {
      const blob = new Blob(chunks.current, { type: mimeType || "audio/webm" });
      const ext = mimeType.includes("mp4") ? "m4a" : mimeType.includes("ogg") ? "ogg" : "webm";
      const form = new FormData();
      form.append("audio", blob, `recording.${ext}`);
      form.append("purpose", purpose);
      form.append("duration_ms", String(durationMs));
      const { data } = await apiUpload<{ data: { id: string } }>(`/learner-profiles/${profileId}/recordings`, form);
      await onRecorded(data.id, durationMs);
    } catch (e) {
      setError(e instanceof Error ? e.message : t.common.errorTitle);
    } finally {
      setState("idle");
    }
  }

  return (
    <div className={cn("flex flex-col gap-2", compact ? "items-start" : "items-center")}>
      {state === "recording" ? (
        <Button variant="destructive" size={compact ? "default" : "lg"} onClick={stop} aria-live="polite">
          <Square className="fill-current" /> {t.recorder.stop} · {f(t.recorder.recording, { seconds })}
        </Button>
      ) : (
        <Button size={compact ? "default" : "lg"} variant={compact ? "outline" : "default"} onClick={start} disabled={disabled} loading={state === "uploading"}>
          <Mic /> {t.recorder.start}
        </Button>
      )}
      {state === "recording" && <span className="h-1 w-24 animate-pulse rounded-full bg-destructive" aria-hidden />}
      {error && <p role="alert" className="max-w-sm text-xs text-destructive">{error}</p>}
    </div>
  );
}
