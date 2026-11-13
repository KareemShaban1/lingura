import { ActivityIndicator, Pressable, StyleSheet, Text, View, type ViewStyle } from "react-native";

export const colors = { primary: "#0f6b62", bg: "#f8f7f4", card: "#ffffff", text: "#1c1b19", muted: "#6b6a66", border: "#e6e3dd", danger: "#c0392b", accent: "#e6a23c" };

export function Screen({ children }: { children: React.ReactNode }) {
  return <View style={styles.screen}>{children}</View>;
}

export function Card({ children, style }: { children: React.ReactNode; style?: ViewStyle }) {
  return <View style={[styles.card, style]}>{children}</View>;
}

export function Button({ title, onPress, variant = "primary", disabled, loading }: { title: string; onPress: () => void; variant?: "primary" | "outline"; disabled?: boolean; loading?: boolean }) {
  return (
    <Pressable accessibilityRole="button" accessibilityState={{ disabled: !!disabled }} onPress={onPress} disabled={disabled || loading}
      style={({ pressed }) => [styles.button, variant === "outline" && styles.outline, (pressed || disabled) && { opacity: 0.7 }]}>
      {loading ? <ActivityIndicator color={variant === "primary" ? "#fff" : colors.primary} /> : <Text style={[styles.buttonText, variant === "outline" && { color: colors.primary }]}>{title}</Text>}
    </Pressable>
  );
}

export function Loading() {
  return <View style={styles.center}><ActivityIndicator color={colors.primary} /></View>;
}

export function Message({ text, action }: { text: string; action?: React.ReactNode }) {
  return <View style={styles.center}><Text style={styles.muted}>{text}</Text>{action}</View>;
}

export const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg, padding: 16, gap: 12 },
  card: { backgroundColor: colors.card, borderRadius: 14, borderWidth: 1, borderColor: colors.border, padding: 16, gap: 6 },
  button: { backgroundColor: colors.primary, borderRadius: 10, paddingVertical: 12, paddingHorizontal: 16, alignItems: "center", minHeight: 44, justifyContent: "center" },
  outline: { backgroundColor: "transparent", borderWidth: 1, borderColor: colors.border },
  buttonText: { color: "#fff", fontWeight: "600", fontSize: 15 },
  center: { flex: 1, alignItems: "center", justifyContent: "center", gap: 12, padding: 24 },
  title: { fontSize: 26, fontWeight: "700", color: colors.text },
  h2: { fontSize: 17, fontWeight: "600", color: colors.text },
  text: { fontSize: 15, color: colors.text },
  muted: { fontSize: 14, color: colors.muted, textAlign: "center" },
  input: { borderWidth: 1, borderColor: colors.border, borderRadius: 10, padding: 12, fontSize: 16, backgroundColor: colors.card, color: colors.text },
});
