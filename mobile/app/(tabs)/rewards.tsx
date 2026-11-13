import { Text, View } from "react-native";
import { useQuery } from "@tanstack/react-query";
import { api } from "@/api";
import { format } from "@/i18n";
import { useSession } from "@/session";
import type { Gamification } from "@/types";
import { Button, Card, Loading, Message, Screen, styles } from "@/ui";

export default function Rewards() {
  const { t } = useSession();
  const q = useQuery({ queryKey: ["gamification"], queryFn: () => api<{ data: Gamification }>("/me/gamification").then((r) => r.data) });
  if (q.isLoading) return <Loading />;
  if (q.error || !q.data) return <Message text={t.error} action={<Button title={t.retry} onPress={() => q.refetch()} />} />;
  const g = q.data;
  return (
    <Screen>
      <Card>
        <Text style={styles.title}>🔥 {format(t.streak, { n: g.streak.current_days })}</Text>
        <Text style={styles.muted}>{format(t.freezes, { n: g.streak.freezes_available })}</Text>
      </Card>
      <View style={{ flexDirection: "row", gap: 10 }}>
        {([[t.xpTotal, g.xp_total], [t.xpWeek, g.xp_week], [t.xpToday, g.xp_today]] as const).map(([label, v]) => (
          <Card key={label} style={{ flex: 1 }}><Text style={styles.title}>{v}</Text><Text style={styles.muted}>XP · {label}</Text></Card>
        ))}
      </View>
      <Text style={styles.muted}>{format(t.achievements, { n: g.achievements_unlocked, total: g.achievements_total })}</Text>
    </Screen>
  );
}
