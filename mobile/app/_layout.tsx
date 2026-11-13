import { useState } from "react";
import { Redirect, Stack, useSegments } from "expo-router";
import { StatusBar } from "expo-status-bar";
import { SafeAreaProvider } from "react-native-safe-area-context";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { SessionProvider, useSession } from "@/session";
import { Loading } from "@/ui";

function Gate() {
  const { ready, user } = useSession();
  const segments = useSegments();
  if (!ready) return <Loading />;
  const onLogin = segments[0] === "login";
  if (!user && !onLogin) return <Redirect href="/login" />;
  if (user && onLogin) return <Redirect href="/" />;
  return <Stack screenOptions={{ headerShown: false }} />;
}

export default function RootLayout() {
  const [client] = useState(() => new QueryClient({ defaultOptions: { queries: { staleTime: 30_000 } } }));
  return (
    <SafeAreaProvider>
      <QueryClientProvider client={client}>
        <SessionProvider>
          <StatusBar style="auto" />
          <Gate />
        </SessionProvider>
      </QueryClientProvider>
    </SafeAreaProvider>
  );
}
