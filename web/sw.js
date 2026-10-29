/* ESP32 DHT Station – service worker (PWA) */
const VERSION = 'esp32dth-v1.0.1';
const STATIC = VERSION + '-static';
const RUNTIME = VERSION + '-runtime';
const PRECACHE = [
  'offline.html',
  'manifest.webmanifest',
  'assets/css/app.css',
  'assets/js/theme.js',
  'assets/js/app.js',
  'assets/js/charts.js',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png'
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE.map((p) => new URL(p, self.registration.scope)))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // API a dynamické dáta nikdy necachujeme
  if (url.origin === location.origin && (url.pathname.includes('/api/') || url.pathname.endsWith('export.php'))) return;

  // Stránky: najprv sieť, pri výpadku offline stránka
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(new URL('offline.html', self.registration.scope))));
    return;
  }

  // Statické súbory a CDN knižnice: stale-while-revalidate
  const isStatic = (url.origin === location.origin && /\.(css|js|png|svg|webmanifest|woff2?)$/.test(url.pathname))
    || url.hostname === 'cdn.jsdelivr.net' || url.hostname.endsWith('gstatic.com') || url.hostname === 'fonts.googleapis.com';
  if (!isStatic) return;

  e.respondWith(
    caches.open(RUNTIME).then(async (cache) => {
      const cached = await caches.match(req);
      const network = fetch(req).then((res) => {
        if (res && (res.ok || res.type === 'opaque')) cache.put(req, res.clone());
        return res;
      }).catch(() => cached);
      return cached || network;
    })
  );
});
