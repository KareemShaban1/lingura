import { FlatList, Linking, Pressable, RefreshControl, Text, View } from "react-native";
import { useQuery } from "@tanstack/react-query";
import { api } from "@/api";
import { format } from "@/i18n";
import { useSession } from "@/session";
import type { Dashboard } from "@/types";
import { Button, Card, colors, Loading, Message, Screen, styles } from "@/ui";

const WEB_URL = (process.env.EXPO_PUBLIC_WEB_URL ?? "http://localhost:3000").replace(/\/$/, "");

/** Today's plan from the same API as the web dashboard. Activities that need the web app open there. */
export default function Today() {
  const { t, profile, rtl } = useSession();
  const q = useQuery({
    queryKey: ["dashboard", profile?.id],
    queryFn: () => api<{ data: Dashboard }>(`/learner-profiles/${profile!.id}/dashboard`).then((r) => r.data),
    enabled: !!profile,
  });
  if (!profile || q.isLoading) return <Loading />;
  if (q.error || !q.data) return <Message text={t.error} action={<Button title={t.retry} onPress={() => q.refetch()} />} />;
  const d = q.data;

  return (
    <Screen>
      <Card style={{ flexDirection: rtl ? "row-reverse" : "row", justifyContent: "space-between" }}>
        <Text style={styles.h2}>🔥 {format(t.streak, { n: d.streak.current_days })}</Text>
        <Text style={styles.muted}>{d.today_minutes_done}/{d.today_minutes_planned} {t.minutes}</Text>
      </Card>
      {!!d.vocabulary_due && <Text style={styles.muted}>{format(t.due, { n: d.vocabulary_due })}</Text>}
      <FlatList
        data={d.today_items}
        keyExtractor={(i) => i.id}
        refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => { q.refetch(); }} />}
        ListEmptyComponent={<Message text={t.todayEmpty} />}
        contentContainerStyle={{ gap: 10 }}
        renderItem={({ item }) => (
          <Pressable accessibilityRole="button" accessibilityHint={t.openOnWeb} onPress={() => Linking.openURL(`${WEB_URL}/learning`)}>
            <Card>
              <Text style={[styles.text, { fontWeight: "600", textAlign: rtl ? "right" : "left" }]}>{item.title}</Text>
              <View style={{ flexDirection: rtl ? "row-reverse" : "row", gap: 8 }}>
                <Text style={styles.muted}>{item.estimated_minutes} {t.minutes}</Text>
                {item.status === "completed" && <Text style={{ color: colors.primary }}>✓ {t.done}</Text>}
              </View>
            </Card>
          </Pressable>
        )}
      />
    </Screen>
  );
}
