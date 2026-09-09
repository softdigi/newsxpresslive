/**
 * NewsXpressLive – Service Worker
 *
 * Strategy:
 *   - Static assets (/web/assets/*)  → Cache-first  (long-lived, versioned by CACHE_NAME)
 *   - HTML pages                     → Network-first (fresh content; fall back to cache)
 *   - Everything else                → Network-only  (do not cache API calls, uploads, etc.)
 *
 * To force an update: increment CACHE_VERSION.
 */

const CACHE_VERSION  = 'v1';
const CACHE_NAME     = 'newsxpresslive-' + CACHE_VERSION;

// Files to pre-cache at install time (must all exist and return 200)
const PRECACHE_ASSETS = [
    '/web/assets/css/style.css',
    '/web/assets/js/app.js',
    '/web/assets/img/placeholder.jpg',
    '/web/assets/img/og-default.jpg',
    '/web/manifest.json',
];

/* ── Install: pre-cache static shell ──────────────────────── */
self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            // addAll() will fail silently per-resource if a file doesn't exist yet;
            // wrap in individual catches so one missing file doesn't abort install.
            return Promise.all(
                PRECACHE_ASSETS.map(function (url) {
                    return cache.add(url).catch(function () { /* ignore missing */ });
                })
            );
        })
    );
    // Activate immediately without waiting for existing clients to close
    self.skipWaiting();
});

/* ── Activate: purge outdated caches ──────────────────────── */
self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys
                    .filter(function (k) { return k !== CACHE_NAME; })
                    .map(function (k) { return caches.delete(k); })
            );
        })
    );
    self.clients.claim();
});

/* ── Fetch: route by resource type ────────────────────────── */
self.addEventListener('fetch', function (event) {
    // Only handle same-origin GET requests
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) return;

    const path = url.pathname;

    // ── Static assets: cache-first ─────────────────────────
    if (path.startsWith('/web/assets/') || path === '/web/manifest.json') {
        event.respondWith(
            caches.match(event.request).then(function (cached) {
                if (cached) return cached;
                return fetch(event.request).then(function (response) {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(function (c) {
                            c.put(event.request, clone);
                        });
                    }
                    return response;
                });
            })
        );
        return;
    }

    // ── HTML pages (.php or /): network-first ──────────────
    if (path.endsWith('.php') || path === '/web/' || path === '/web') {
        event.respondWith(
            fetch(event.request)
                .then(function (response) {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(function (c) {
                            c.put(event.request, clone);
                        });
                    }
                    return response;
                })
                .catch(function () {
                    // Offline fallback: return cached version if available
                    return caches.match(event.request)
                        || caches.match('/web/');
                })
        );
        return;
    }

    // Everything else: network-only (uploads, admin pages, etc.)
});
