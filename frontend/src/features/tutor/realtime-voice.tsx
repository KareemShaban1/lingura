"use client";

import { useEffect, useRef, useState } from "react";
import { PhoneOff, Radio } from "lucide-react";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { toast } from "sonner";
import { isQuotaError, toastError } from "@/lib/errors";

type Session = { client_secret: string; model: string; provider: string; max_seconds: number };

/**
 * Live voice over WebRTC, directly between the browser and the provider, authenticated with a short-lived
 * secret minted by our backend (instructions are fixed server-side). Final transcripts are posted back so the
 * conversation, report and analytics stay complete. Falls back to push-to-talk on any failure.
 */
export function RealtimeVoice({ conversationId, onTranscript }: { conversationId: string; onTranscript: () => void }) {
  const { t } = useI18n();
  const [state, setState] = useState<"idle" | "connecting" | "live" | "failed">("idle");
  const pc = useRef<RTCPeerConnection | null>(null);
  const audio = useRef<HTMLAudioElement | null>(null);
  const limit = useRef<number | null>(null);

  useEffect(() => () => { pc.current?.close(); if (limit.current) window.clearTimeout(limit.current); }, []);

  async function save(role: "learner" | "assistant", text: string) {
    if (!text.trim()) return;
    await api(`/conversations/${conversationId}/realtime-transcript`, { method: "POST", body: { role, text } }).catch(() => undefined);
    onTranscript();
  }

  async function connect() {
    setState("connecting");
    try {
      const { data } = await api<{ data: Session }>(`/conversations/${conversationId}/realtime-session`, { method: "POST" });
      if (data.provider !== "openai") throw new Error("unsupported provider");

      const peer = new RTCPeerConnection();
      pc.current = peer;
      audio.current ??= Object.assign(new Audio(), { autoplay: true });
      peer.ontrack = (e) => { audio.current!.srcObject = e.streams[0]; };
      const mic = await navigator.mediaDevices.getUserMedia({ audio: true });
      peer.addTrack(mic.getTracks()[0], mic);

      const events = peer.createDataChannel("oai-events");
      events.onopen = () => events.send(JSON.stringify({ type: "response.create" })); // tutor greets first
      events.onmessage = (e) => {
        const event = JSON.parse(e.data);
        if (event.type === "conversation.item.input_audio_transcription.completed") save("learner", event.transcript ?? "");
        if (event.type === "response.output_audio_transcript.done" || event.type === "response.audio_transcript.done") save("assistant", event.transcript ?? "");
      };

      const offer = await peer.createOffer();
      await peer.setLocalDescription(offer);
      const response = await fetch(`https://api.openai.com/v1/realtime/calls?model=${encodeURIComponent(data.model)}`, {
        method: "POST",
        body: offer.sdp,
        headers: { Authorization: `Bearer ${data.client_secret}`, "Content-Type": "application/sdp" },
      });
      if (!response.ok) throw new Error(`realtime ${response.status}`);
      await peer.setRemoteDescription({ type: "answer", sdp: await response.text() });
      setState("live");
      // The session was pre-paid as a block of minutes: end the call when it runs out (the learner can start another).
      limit.current = window.setTimeout(() => { disconnect(); toast.info(t.tutor.blockEnded); }, data.max_seconds * 1000);
    } catch (e) {
      pc.current?.close();
      setState("failed");
      if (isQuotaError(e)) toastError(e, t.common.errorTitle);
    }
  }

  function disconnect() {
    if (limit.current) window.clearTimeout(limit.current);
    limit.current = null;
    pc.current?.getSenders().forEach((s) => s.track?.stop());
    pc.current?.close();
    setState("idle");
  }

  return (
    <div className="flex flex-wrap items-center gap-3">
      {state === "live" ? (
        <>
          <span className="flex items-center gap-2 text-sm font-medium text-success"><Radio className="size-4 animate-pulse" /> {t.tutor.liveConnected}</span>
          <Button variant="outline" size="sm" onClick={disconnect}><PhoneOff /> {t.tutor.disconnectLive}</Button>
        </>
      ) : (
        <Button variant="secondary" size="sm" onClick={connect} loading={state === "connecting"}><Radio /> {t.tutor.connectLive}</Button>
      )}
      {state === "failed" && <p role="alert" className="text-xs text-destructive">{t.tutor.liveFailed}</p>}
    </div>
  );
}
