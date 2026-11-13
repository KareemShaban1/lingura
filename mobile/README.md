# LinguaForge mobile (Expo)

A companion app on the same API as the web app, using **token authentication**: signing in sends
`device_name`, the API returns a Sanctum personal access token, and the token is kept in the platform
keychain/keystore (`expo-secure-store`). Signing out revokes the token on the server.

Screens: today's plan (activities open in the web app), FSRS flashcard review (with pinyin for Chinese),
rewards (XP, streak, freezes) and profile (switch language, sign out). Lessons, the AI tutor, writing,
placement and exam practice stay in the web app for now.

```bash
cp .env.example .env   # set EXPO_PUBLIC_API_URL / EXPO_PUBLIC_WEB_URL (use your LAN IP on a device)
npm install
npx expo start
```

Checks: `npx tsc --noEmit` and `npx expo export --platform android` (both run in CI).
Not yet done: store builds (EAS), push notifications, native offline packs, RTL layout mirroring.
