// service-worker.js — MIAUDITOPS PWA Service Worker
const CACHE_NAME = 'miauditops-v2';
const OFFLINE_URL = '/offline.html';

// Assets to pre-cache for instant load
const PRECACHE_ASSETS = [
    '/dashboard/index.php',
    '/dashboard/station_audit.php',
    '/dashboard/requisitions.php',
    '/offline.html',
    'https://cdn.tailwindcss.com',
    'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap',
    'https://unpkg.com/lucide@latest',
];

// Install: pre-cache core assets individually to prevent 302 redirect failures
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(async cache => {
            for (let url of PRECACHE_ASSETS) {
                try {
                    const response = await fetch(url);
                    if (response.ok) {
                        await cache.put(url, response);
                    } else {
                        console.warn(`[SW] Pre-cache failed for ${url} (HTTP ${response.status})`);
                    }
                } catch (err) {
                    console.warn(`[SW] Pre-cache network err for ${url}:`, err);
                }
            }
        })
    );
    self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
            )
        )
    );
    self.clients.claim();
});

// Fetch: network-first for pages, cache-first for assets
self.addEventListener('fetch', event => {
    const { request } = event;

    // Skip non-GET requests (form submissions, AJAX POSTs)
    if (request.method !== 'GET') return;

    // Skip admin/auth/ajax pages — never cache these
    if (request.url.includes('/auth/') ||
        request.url.includes('/ajax/') ||
        request.url.includes('/config/') ||
        request.url.includes('/owner/')) {
        return;
    }

    // For navigation (HTML pages): Network First
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(response => {
                    // Cache a copy for offline use
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
                    return response;
                })
                .catch(() => {
                    // Offline: try cache, then offline page
                    return caches.match(request).then(cached => cached || caches.match(OFFLINE_URL));
                })
        );
        return;
    }

    // For static assets (CSS, JS, images, fonts): Cache First
    if (request.url.match(/\.(css|js|png|jpg|jpeg|webp|svg|woff2?|ttf|ico)(\?|$)/)) {
        event.respondWith(
            caches.match(request).then(cached => {
                if (cached) return cached;
                return fetch(request).then(response => {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
                    return response;
                });
            })
        );
        return;
    }

    // Everything else: Network First with cache fallback
    event.respondWith(
        fetch(request)
            .then(response => {
                const clone = response.clone();
                caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
                return response;
            })
            .catch(() => caches.match(request))
    );
});
