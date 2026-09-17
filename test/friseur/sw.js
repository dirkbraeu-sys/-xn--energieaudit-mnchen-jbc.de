/* ===========================================================
   Service Worker – Grundlage fürs "Als App installieren" (PWA).
   Bewusst zurückhaltend: nur statische Dateien (HTML/CSS/JS/Icons) werden
   gecacht, damit die Seite auch bei wackliger Verbindung schnell lädt und
   sich installieren lässt. API-Aufrufe (api/index.php) werden NIE aus dem
   Cache bedient, sondern immer live vom Server geholt – ein Buchungssystem
   mit veralteten freien Zeitfenstern aus dem Cache wäre schlimmer als gar
   kein Offline-Komfort.
   =========================================================== */
const CACHE_NAME = "friseur-static-v1";
const CORE_ASSETS = [
  "./",
  "./index.html",
  "./styles.css",
  "./script.js",
  "./manifest.json",
  "./icons/icon-192.png",
  "./icons/icon-512.png"
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(CORE_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);

  // Nur eigene, statische GET-Anfragen behandeln - alles andere (API-Aufrufe,
  // fremde Domains, POST-Requests) läuft ganz normal direkt übers Netzwerk.
  if (event.request.method !== "GET" || url.origin !== self.location.origin) return;
  if (url.pathname.includes("/api/")) return;

  event.respondWith(
    caches.match(event.request).then((cached) => {
      const network = fetch(event.request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
          }
          return response;
        })
        .catch(() => cached);
      // Stale-while-revalidate: sofort die zwischengespeicherte Version zeigen
      // (falls vorhanden), im Hintergrund aber die Netzwerk-Version nachladen
      // und den Cache aktualisieren - so bleiben Änderungen nicht dauerhaft hängen.
      return cached || network;
    })
  );
});
