/**
 * MIAUDITOPS — Service Worker (PWA)
 * Caches static assets for faster loading + offline shell
 * v1.0.0
 */

const CACHE_NAME = 'miauditops-v1';
const STATIC_ASSETS = [
  '/miiauditops/assets/images/logo.png',
  '/miiauditops/assets/images/logo-dark.png',
  '/miiauditops/assets/css/styles.css',
  '/miiauditops/assets/js/print-utils.js',
  '/miiauditops/assets/js/anti-copy.js',
  'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap',
  'https://cdn.tailwindcss.com',
  'https://unpkg.com/lucide@latest',
];

// Install: cache static assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(STATIC_ASSETS).catch(err => {
        console.warn('[SW] Some assets failed to cache:', err);
      });
    })
  );
  self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
      );
    })
  );
  self.clients.claim();
});

// Fetch: Network-first for HTML/API, Cache-first for static assets
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Skip non-GET requests (form submissions, API calls)
  if (event.request.method !== 'GET') return;

  // Skip API requests — always go to network
  if (url.pathname.includes('/ajax/') || url.pathname.includes('/api/')) return;

  // For navigation (HTML pages): network-first with fallback
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request).catch(() => {
        return caches.match(event.request).then(cached => {
          return cached || new Response(
            '<html><body style="font-family:Inter,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#e2e8f0;text-align:center"><div><h1 style="font-size:2rem;margin-bottom:1rem">📡 You\'re Offline</h1><p>MIAUDITOPS requires an internet connection.<br>Please reconnect and try again.</p></div></body></html>',
            { headers: { 'Content-Type': 'text/html' } }
          );
        });
      })
    );
    return;
  }

  // For static assets: cache-first with network fallback
  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) return cached;
      return fetch(event.request).then(response => {
        // Cache successful responses for static assets
        if (response.ok && (url.pathname.match(/\.(css|js|png|jpg|svg|woff2?)$/) || url.hostname !== location.hostname)) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => cached);
    })
  );
});
