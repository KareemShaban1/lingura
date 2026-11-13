/*
 * LinguaForge service worker.
 * - Caches the static app shell (Next build assets, icons) for fast repeat loads.
 * - Offline mode: the /offline page and its assets are cached when a lesson is saved for offline use;
 *   failed navigations while offline fall back to it. Lesson packs live in IndexedDB, not here.
 * API responses are never cached: learning data must always be fresh and private.
 */
const CACHE = "lf-shell-v2";
const OFFLINE_URL = "/offline";

self.addEventListener("install", () => self.skipWaiting());

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
  );
});

/** Caches the offline page and every static asset it references. */
async function precacheOffline() {
  const cache = await caches.open(CACHE);
  const response = await fetch(OFFLINE_URL, { credentials: "same-origin" });
  if (!response.ok) return;
  const html = await response.clone().text();
  await cache.put(OFFLINE_URL, response);
  const assets = [...new Set([...html.matchAll(/(?:src|href)="(\/_next\/static\/[^"]+)"/g)].map((m) => m[1]))];
  await Promise.all(assets.map((url) => cache.match(url).then((hit) => hit || fetch(url).then((r) => r.ok && cache.put(url, r)))));
}

self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "precache-offline") {
    event.waitUntil(precacheOffline().then(() => event.source && event.source.postMessage({ type: "offline-ready" })).catch(() => undefined));
  }
});

self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== "GET" || url.origin !== self.location.origin) return;

  if (event.request.mode === "navigate") {
    // Network first; when offline, serve the cached offline page (the only page that works without the API).
    event.respondWith(fetch(event.request).catch(async () => (await caches.match(OFFLINE_URL)) || Response.error()));
    return;
  }

  if (url.pathname.startsWith("/_next/static/") || /\.(png|svg|ico|woff2)$/.test(url.pathname)) {
    event.respondWith(
      caches.open(CACHE).then(async (cache) => {
        const hit = await cache.match(event.request);
        if (hit) return hit;
        const response = await fetch(event.request);
        if (response.ok) cache.put(event.request, response.clone());
        return response;
      }),
    );
  }
});
