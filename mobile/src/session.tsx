import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { I18nManager } from "react-native";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { api, ApiError, loadToken, setToken } from "./api";
import { strings, type Strings } from "./i18n";
import type { LearnerProfile, User } from "./types";

type Session = {
  ready: boolean;
  user: User | null;
  profile: LearnerProfile | null;
  setProfileId: (id: string) => void;
  t: Strings;
  rtl: boolean;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
};

const SessionContext = createContext<Session | null>(null);

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const qc = useQueryClient();
  const [hasToken, setHasToken] = useState<boolean | null>(null);
  const [profileId, setProfileId] = useState<string | null>(null);
  useEffect(() => {
    loadToken().then((tk) => setHasToken(!!tk));
  }, []);

  const me = useQuery({
    queryKey: ["me"],
    queryFn: () => api<{ data: User }>("/auth/me").then((r) => r.data),
    enabled: hasToken === true,
    retry: (n, e) => !(e instanceof ApiError && e.status === 401) && n < 2,
  });

  // An expired or revoked token signs the device out.
  useEffect(() => {
    if (me.error instanceof ApiError && me.error.status === 401) {
      setToken(null).then(() => setHasToken(false));
    }
  }, [me.error]);

  const signIn = useCallback(async (email: string, password: string) => {
    const res = await api<{ token: string }>("/auth/login", { method: "POST", body: { email, password, device_name: "LinguaForge mobile" } });
    await setToken(res.token);
    setHasToken(true);
  }, []);

  const signOut = useCallback(async () => {
    await api("/auth/logout", { method: "POST" }).catch(() => undefined); // revokes this device's token
    await setToken(null);
    qc.clear();
    setHasToken(false);
  }, [qc]);

  const user = hasToken ? me.data ?? null : null;
  const profile = user?.learner_profiles.find((p) => p.id === profileId) ?? user?.learner_profiles[0] ?? null;
  const locale = user?.profile.interface_language;
  const value = useMemo<Session>(() => ({
    ready: hasToken === false || (hasToken === true && (me.isSuccess || me.isError)),
    user, profile, setProfileId, t: strings(locale), rtl: locale === "ar" || I18nManager.isRTL, signIn, signOut,
  }), [hasToken, me.isSuccess, me.isError, user, profile, locale, signIn, signOut]);

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession() {
  const s = useContext(SessionContext);
  if (!s) throw new Error("useSession outside SessionProvider");
  return s;
}
