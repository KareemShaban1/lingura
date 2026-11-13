import { Ionicons } from "@expo/vector-icons";
import { Tabs } from "expo-router";
import type { ColorValue } from "react-native";
import { useSession } from "@/session";
import { colors } from "@/ui";

export default function TabsLayout() {
  const { t } = useSession();
  const icon = (name: keyof typeof Ionicons.glyphMap) => ({ color, size }: { color: ColorValue; size: number }) => <Ionicons name={name} color={typeof color === "number" ? undefined : color ?? undefined} size={size} />;
  return (
    <Tabs screenOptions={{ tabBarActiveTintColor: colors.primary, headerTitleStyle: { fontWeight: "600" } }}>
      <Tabs.Screen name="index" options={{ title: t.today, tabBarIcon: icon("home-outline") }} />
      <Tabs.Screen name="review" options={{ title: t.review, tabBarIcon: icon("layers-outline") }} />
      <Tabs.Screen name="rewards" options={{ title: t.rewards, tabBarIcon: icon("trophy-outline") }} />
      <Tabs.Screen name="profile" options={{ title: t.profile, tabBarIcon: icon("person-outline") }} />
    </Tabs>
  );
}
