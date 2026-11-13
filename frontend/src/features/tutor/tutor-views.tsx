"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { ArrowRight, ChevronDown, Lightbulb, MessageCircle, Send, Sparkles, Volume2 } from "lucide-react";
import { Choice } from "@/components/choice";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Select } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { EmptyState, ErrorState } from "@/components/ui/states";
import { Skeleton } from "@/components/ui/skeleton";
import { Recorder } from "@/features/speech/recorder";
import { useActiveProfile, useConversation, useConversations, useInvalidateProfile, useTutorCatalog } from "@/features/queries";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import type { Conversation, TutorMessage } from "@/lib/types";
import { cn } from "@/lib/utils";
import { RealtimeVoice } from "./realtime-voice";
import { toastError } from "@/lib/errors";
import type { TutorCatalog } from "@/lib/types";

/** "12 messages left today" with an upgrade link when the plan limit is close — shown before a 429 can happen. */
function QuotaHint({ quota }: { quota?: TutorCatalog["quota"]["tutor_messages"] }) {
  const { t, f } = useI18n();
  if (!quota || quota.limit === null || quota.remaining === null) return null;
  const low = quota.remaining <= Math.max(2, Math.floor(quota.limit * 0.2));
  return (
    <p className="text-sm text-muted-foreground">
      {f(t.tutor.messagesLeft, { n: quota.remaining })}
      {low && <> · <Link href="/pricing" className="font-medium text-primary hover:underline">{t.billing.upgrade}</Link></>}
    </p>
  );
}

export function TutorHome() {
  const { t, f } = useI18n();
  const profile = useActiveProfile();
  const params = useSearchParams();
  const router = useRouter();
  const catalog = useTutorCatalog(profile?.id);
  const history = useConversations(profile?.id);
  const [tutor, setTutor] = useState("lina");
  const [scenario, setScenario] = useState<string | null>(params.get("scenario"));
  const [level, setLevel] = useState<string | null>(null);
  const [mode, setMode] = useState<"text" | "voice" | "realtime">("text");
  const [starting, setStarting] = useState(false);

  if (catalog.error) return <ErrorState error={catalog.error} onRetry={() => catalog.refetch()} />;
  if (!catalog.data || !profile) return <div className="space-y-4"><Skeleton className="h-10 w-1/3" /><Skeleton className="h-40" /><Skeleton className="h-64" /></div>;
  const c = catalog.data;
  if (!c.capabilities.text) return <EmptyState title={t.tutor.unavailable} />;

  async function start() {
    setStarting(true);
    try {
      const { data } = await api<{ data: Conversation }>(`/learner-profiles/${profile!.id}/conversations`, {
        method: "POST",
        body: { tutor, scenario, level: level ?? c.default_level, mode, session_id: params.get("session") },
      });
      router.push(`/tutor/${data.id}`);
    } catch (e) {
      toastError(e, t.common.errorTitle);
      setStarting(false);
    }
  }

  return (
    <div className="space-y-8">
      <div>
        <h1 className="font-display text-3xl md:text-4xl">{t.tutor.title}</h1>
        <p className="mt-1 max-w-2xl text-muted-foreground">{t.tutor.subtitle}</p>
      </div>

      <section>
        <h2 className="mb-3 text-lg font-semibold">{t.tutor.chooseTutor}</h2>
        <div role="radiogroup" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {c.tutors.map((tu) => (
            <Choice key={tu.slug} selected={tutor === tu.slug} onSelect={() => setTutor(tu.slug)}
              title={<span className="flex items-center gap-2"><span className="grid size-8 place-items-center rounded-full bg-secondary font-display text-secondary-foreground">{tu.name[0]}</span>{tu.name}</span>}
              description={tu.description} />
          ))}
        </div>
      </section>

      <section>
        <h2 className="mb-3 text-lg font-semibold">{t.tutor.chooseScenario}</h2>
        <div role="radiogroup" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {c.scenarios.map((s) => (
            <Choice key={s.slug} selected={scenario === s.slug} onSelect={() => setScenario(scenario === s.slug ? null : s.slug)}
              title={s.title}
              description={<>{s.description}<span className="mt-1 block font-mono text-[11px]">{s.min_level}–{s.max_level}</span></>}
              aside={s.recommended ? <Badge variant="accent">{t.tutor.recommended}</Badge> : null} />
          ))}
        </div>
      </section>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="level">{t.tutor.level}</Label>
          <Select id="level" value={level ?? c.default_level} onChange={(e) => setLevel(e.target.value)}>
            {c.levels.map((l) => <option key={l.code} value={l.code}>{l.label}</option>)}
          </Select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="mode">{t.tutor.mode}</Label>
          <Select id="mode" value={mode} onChange={(e) => setMode(e.target.value as typeof mode)}>
            <option value="text">{t.tutor.modes.text}</option>
            {c.capabilities.voice && <option value="voice">{t.tutor.modes.voice}</option>}
            {c.capabilities.realtime && <option value="realtime">{t.tutor.modes.realtime}</option>}
          </Select>
          {mode === "realtime" && <p className="text-xs text-muted-foreground">{t.tutor.realtimeNote}</p>}
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Button size="lg" onClick={start} loading={starting}><MessageCircle /> {t.tutor.start}</Button>
        <QuotaHint quota={c.quota.tutor_messages} />
      </div>

      <section>
        <h2 className="mb-3 text-lg font-semibold">{t.tutor.history}</h2>
        {!history.data?.length ? <p className="text-sm text-muted-foreground">{t.tutor.noHistory}</p> : (
          <ul className="divide-y rounded-xl border bg-card">
            {history.data.map((cv) => (
              <li key={cv.id}>
                <Link href={cv.status === "active" ? `/tutor/${cv.id}` : `/tutor/${cv.id}/report`} className="flex items-center gap-3 p-4 hover:bg-muted/50">
                  <span className="grid size-9 place-items-center rounded-full bg-secondary font-display">{cv.tutor.name[0]}</span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{cv.scenario?.title ?? cv.tutor.name}</p>
                    <p className="text-xs text-muted-foreground">{new Date(cv.started_at).toLocaleDateString()} · {f(t.tutor.turns, { n: cv.learner_turns })} · {cv.level.label}</p>
                  </div>
                  <ArrowRight className="size-4 text-muted-foreground rtl:rotate-180" />
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function PlayButton({ messageId }: { messageId: number }) {
  const { t } = useI18n();
  const [loading, setLoading] = useState(false);
  async function play() {
    setLoading(true);
    try {
      const { url } = await api<{ url: string }>(`/ai-messages/${messageId}/audio`);
      await new Audio(url).play();
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setLoading(false);
    }
  }
  return <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={play} loading={loading}><Volume2 /> {t.tutor.play}</Button>;
}

function MessageBubble({ m, lang, dir, canPlay }: { m: TutorMessage; lang: string; dir: "ltr" | "rtl"; canPlay: boolean }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(true);
  const learner = m.role === "learner";
  return (
    <li className={cn("flex flex-col gap-1", learner ? "items-end" : "items-start")}>
      <div lang={lang} dir={dir} className={cn("max-w-[85%] rounded-2xl px-4 py-2.5 text-base leading-relaxed",
        learner ? "rounded-ee-sm bg-primary text-primary-foreground" : "rounded-es-sm border bg-card")}>
        {m.content}
      </div>
      {!learner && canPlay && <PlayButton messageId={m.id} />}
      {learner && (m.corrections.length > 0 || m.natural_alternative) && (
        <div className="w-full max-w-[85%] rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm">
          <button className="flex w-full items-center justify-between text-xs font-semibold" onClick={() => setOpen(!open)} aria-expanded={open}>
            <span className="flex items-center gap-1"><Lightbulb className="size-3.5" /> {t.tutor.corrections}</span>
            <ChevronDown className={cn("size-4 transition-transform", open && "rotate-180")} />
          </button>
          {open && (
            <ul className="mt-2 space-y-2">
              {m.corrections.map((c, i) => (
                <li key={i}>
                  <p lang={lang} dir={dir}><span className="line-through decoration-destructive/70">{c.original}</span> → <strong>{c.correction}</strong></p>
                  <p dir="auto" className="text-muted-foreground">{c.explanation}</p>
                </li>
              ))}
              {m.natural_alternative && (
                <li><span className="text-xs text-muted-foreground">{t.tutor.natural}: </span><span lang={lang} dir={dir}>{m.natural_alternative}</span></li>
              )}
            </ul>
          )}
        </div>
      )}
    </li>
  );
}

export function ConversationView() {
  const { id } = useParams<{ id: string }>();
  const { t, f } = useI18n();
  const router = useRouter();
  const profile = useActiveProfile();
  const qc = useQueryClient();
  const invalidate = useInvalidateProfile();
  const conversation = useConversation(id);
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [finishing, setFinishing] = useState(false);
  const [newWord, setNewWord] = useState<{ term: string; meaning: string } | null>(null);
  const bottom = useRef<HTMLDivElement>(null);
  const lastReplyAt = useRef(0);
  const openedAt = useRef(0);
  const catalog = useTutorCatalog(profile?.id);

  const messages = conversation.data?.messages ?? [];
  useEffect(() => { bottom.current?.scrollIntoView({ behavior: "smooth", block: "end" }); }, [messages.length, busy]);
  useEffect(() => {
    openedAt.current = Date.now();
    lastReplyAt.current = Date.now();
  }, []);

  if (conversation.error) return <ErrorState error={conversation.error} onRetry={() => conversation.refetch()} />;
  if (!conversation.data || !profile) return <Skeleton className="h-[60vh]" />;
  const cv = conversation.data;
  const active = cv.status === "active";
  const canVoice = !!catalog.data?.capabilities.voice;

  function appendTurn(data: { learner: TutorMessage; reply: TutorMessage; new_vocabulary: { term: string; meaning: string }[] }) {
    qc.setQueryData<Conversation>(["conversation", id], (old) => old && { ...old, messages: [...(old.messages ?? []), data.learner, data.reply] });
    setNewWord(data.new_vocabulary[0] ?? null);
    lastReplyAt.current = Date.now();
  }

  async function send(body: Record<string, unknown>, path: "messages" | "voice") {
    setBusy(true);
    try {
      const { data } = await api<{ data: { learner: TutorMessage; reply: TutorMessage; new_vocabulary: { term: string; meaning: string }[] } }>(
        `/conversations/${id}/${path}`, { method: "POST", body: { ...body, response_ms: Date.now() - lastReplyAt.current } });
      appendTurn(data);
      catalog.refetch(); // remaining messages
    } catch (e) {
      toastError(e, t.common.errorTitle);
    } finally {
      setBusy(false);
    }
  }

  async function finish() {
    setFinishing(true);
    try {
      await api(`/conversations/${id}/finish`, { method: "POST", body: { active_seconds: Math.round((Date.now() - openedAt.current) / 1000) } });
      invalidate(profile!.id);
      router.push(`/tutor/${id}/report`);
    } catch (e) {
      toastError(e, t.common.errorTitle);
      setFinishing(false);
    }
  }

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="grid size-11 place-items-center rounded-full bg-secondary font-display text-lg">{cv.tutor.name[0]}</span>
          <div>
            <p className="font-semibold">{cv.tutor.name} · {cv.scenario?.title ?? t.tutor.title}</p>
            <p className="text-xs text-muted-foreground">{cv.language.name} · {cv.level.label}{cv.tutor.correction_mode === "end_only" && ` · ${f(t.tutor.endOnlyNote, { name: cv.tutor.name })}`}</p>
          </div>
        </div>
        {active ? (
          <Dialog>
            <DialogTrigger asChild><Button variant="outline" size="sm" disabled={busy}>{t.tutor.finish}</Button></DialogTrigger>
            <DialogContent>
              <DialogTitle>{t.tutor.finish}</DialogTitle>
              <DialogDescription>{t.tutor.finishConfirm}</DialogDescription>
              <div className="mt-6 flex justify-end gap-2">
                <DialogClose asChild><Button variant="outline">{t.common.cancel}</Button></DialogClose>
                <Button onClick={finish} loading={finishing}>{t.tutor.finish}</Button>
              </div>
            </DialogContent>
          </Dialog>
        ) : <Button asChild size="sm"><Link href={`/tutor/${id}/report`}>{t.tutor.viewReport}</Link></Button>}
      </div>

      {active && <QuotaHint quota={catalog.data?.quota.tutor_messages} />}
      {cv.mode === "realtime" && active && <RealtimeVoice conversationId={id} onTranscript={() => conversation.refetch()} />}

      <ol className="flex min-h-[45vh] flex-col gap-4 rounded-2xl border bg-muted/30 p-4" aria-live="polite">
        {messages.map((m) => <MessageBubble key={m.id} m={m} lang={cv.language.code} dir={cv.language.direction} canPlay={canVoice} />)}
        {busy && <li className="text-sm text-muted-foreground">{t.tutor.thinking}</li>}
        <div ref={bottom} />
      </ol>

      {newWord && (
        <p className="flex items-center gap-2 text-sm"><Sparkles className="size-4 text-accent" /> {t.tutor.newWord}: <strong lang={cv.language.code}>{newWord.term}</strong> — <span dir="auto">{newWord.meaning}</span></p>
      )}

      {active ? (
        <form className="flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); if (text.trim()) { send({ text }, "messages"); setText(""); } }}>
          <textarea value={text} onChange={(e) => setText(e.target.value)} rows={2} lang={cv.language.code} dir="auto"
            placeholder={t.tutor.typeMessage} aria-label={t.tutor.typeMessage} disabled={busy}
            onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); e.currentTarget.form?.requestSubmit(); } }}
            className="min-h-12 flex-1 resize-none rounded-xl border border-input bg-card px-3 py-2.5 text-base focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40" />
          {canVoice && cv.mode !== "text" && (
            <Recorder compact profileId={profile.id} purpose="conversation" disabled={busy} onRecorded={(rid) => send({ recording_id: rid }, "voice")} />
          )}
          <Button type="submit" size="icon" aria-label={t.tutor.send} disabled={busy || !text.trim()}><Send className="rtl:rotate-180" /></Button>
        </form>
      ) : <p className="text-center text-sm text-muted-foreground">{t.tutor.ended}</p>}
    </div>
  );
}
