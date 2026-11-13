import { useState } from "react";
import { KeyboardAvoidingView, Platform, Text, TextInput, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { useSession } from "@/session";
import { Button, colors, styles } from "@/ui";

export default function Login() {
  const { t, signIn } = useSession();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await signIn(email.trim(), password);
    } catch (e) {
      setError(e instanceof Error ? e.message : t.error);
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }}>
      <KeyboardAvoidingView behavior={Platform.OS === "ios" ? "padding" : undefined} style={{ flex: 1, justifyContent: "center", padding: 24, gap: 12 }}>
        <Text style={styles.title}>LinguaForge</Text>
        <View style={{ gap: 6 }}>
          <Text style={styles.text}>{t.email}</Text>
          <TextInput style={styles.input} value={email} onChangeText={setEmail} autoCapitalize="none" autoComplete="email" keyboardType="email-address" textContentType="emailAddress" accessibilityLabel={t.email} />
        </View>
        <View style={{ gap: 6 }}>
          <Text style={styles.text}>{t.password}</Text>
          <TextInput style={styles.input} value={password} onChangeText={setPassword} secureTextEntry autoComplete="password" textContentType="password" accessibilityLabel={t.password} />
        </View>
        {error && <Text accessibilityRole="alert" style={{ color: colors.danger }}>{error}</Text>}
        <Button title={busy ? t.signingIn : t.signIn} onPress={submit} loading={busy} disabled={!email || !password} />
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
