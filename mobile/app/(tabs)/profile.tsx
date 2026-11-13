import { Pressable, Text } from "react-native";
import { useSession } from "@/session";
import { Button, Card, colors, Screen, styles } from "@/ui";

export default function Profile() {
  const { t, user, profile, setProfileId, signOut } = useSession();
  if (!user) return null;
  return (
    <Screen>
      <Card>
        <Text style={styles.h2}>{user.name}</Text>
        <Text style={styles.muted}>{user.email}</Text>
      </Card>
      <Text style={styles.h2}>{t.learning}</Text>
      {user.learner_profiles.map((p) => (
        <Pressable key={p.id} accessibilityRole="radio" accessibilityState={{ selected: p.id === profile?.id }} onPress={() => setProfileId(p.id)}>
          <Card style={p.id === profile?.id ? { borderColor: colors.primary, borderWidth: 2 } : undefined}>
            <Text style={styles.text}>{p.target_language.native_name}</Text>
          </Card>
        </Pressable>
      ))}
      <Text style={styles.muted}>{t.webOnly}</Text>
      <Button title={t.signOut} variant="outline" onPress={signOut} />
    </Screen>
  );
}
