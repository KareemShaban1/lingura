import { useState } from "react";
import { Text, View } from "react-native";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/api";
import { useSession } from "@/session";
import type { Card as Flashcard } from "@/types";
import { Button, Card, colors, Loading, Message, Screen, styles } from "@/ui";

/** FSRS flashcard review — the same scheduling endpoint as the web app. */
export default function Review() {
  const { t, profile } = useSession();
  const qc = useQueryClient();
  const [index, setIndex] = useState(0);
  const [revealed, setRevealed] = useState(false);
  const [busy, setBusy] = useState(false);
  const started = useState(() => Date.now());
  const q = useQuery({
    queryKey: ["review-queue", profile?.id],
    queryFn: () => api<{ data: Flashcard[] }>(`/learner-profiles/${profile!.id}/vocabulary/review-queue?limit=20`).then((r) => r.data),
    enabled: !!profile,
  });
  if (!profile || q.isLoading) return <Loading />;
  if (q.error || !q.data) return <Message text={t.error} action={<Button title={t.retry} onPress={() => q.refetch()} />} />;
  const card = q.data[index];
  if (!card) return <Message text={t.reviewDone} action={<Button title={t.retry} variant="outline" onPress={() => { setIndex(0); q.refetch(); }} />} />;
  const lang = profile.target_language;

  async function rate(rating: 1 | 2 | 3 | 4) {
    setBusy(true);
    try {
      await api(`/user-vocabulary/${card.id}/reviews`, { method: "POST", body: { rating, duration_ms: Date.now() - started[0] } });
      setIndex((i) => i + 1);
      setRevealed(false);
      qc.invalidateQueries({ queryKey: ["dashboard"] });
      qc.invalidateQueries({ queryKey: ["gamification"] });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <Text style={styles.muted}>{index + 1} / {q.data.length}</Text>
      <Card style={{ alignItems: "center", paddingVertical: 32, gap: 10 }}>
        <Text style={{ fontSize: 40, fontWeight: "600", color: colors.text, writingDirection: lang.direction }}>{card.word.lemma}</Text>
        {card.word.pronunciation && <Text style={[styles.muted, { fontSize: 18 }]}>{card.word.pronunciation}</Text>}
        <Text style={styles.muted}>{card.word.part_of_speech}</Text>
        {revealed && (
          <View style={{ alignItems: "center", gap: 8, marginTop: 8 }}>
            <Text style={[styles.h2, { textAlign: "center" }]}>{card.word.meaning}</Text>
            {card.word.examples[0] && <Text style={[styles.text, { textAlign: "center", writingDirection: lang.direction }]}>{card.word.examples[0].sentence}</Text>}
            {card.word.examples[0]?.translation && <Text style={styles.muted}>{card.word.examples[0].translation}</Text>}
          </View>
        )}
      </Card>
      {!revealed ? (
        <Button title={t.show} onPress={() => setRevealed(true)} />
      ) : (
        <View style={{ flexDirection: "row", gap: 8 }}>
          {([[1, t.again], [2, t.hard], [3, t.good], [4, t.easy]] as const).map(([r, label]) => (
            <View key={r} style={{ flex: 1 }}><Button title={label} variant={r === 3 ? "primary" : "outline"} onPress={() => rate(r)} disabled={busy} /></View>
          ))}
        </View>
      )}
    </Screen>
  );
}
