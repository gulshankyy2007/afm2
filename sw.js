/**
 * AFM#2 - Progressive Web App Service Worker (v2)
 * Ensures standalone installability on Android/Chrome (WebAPK)
 * while preserving live, dynamic server-side session and tournament integrity.
 */

const CACHE_NAME = 'afm2-pwa-v2';
const STATIC_ASSETS = [
    './assets/img/afm2-favicon-192x192.png',
    './assets/img/afm2-favicon-256x256.png',
    './assets/img/afm2-favicon-512x512.png',
    './favicon.ico'
];

// Install event: cache core static icon assets and activate immediately
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch((err) => {
                // If offline or asset fetch fails, proceed with installation
                console.warn('[AFM2 SW] Precache warning:', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// Activate event: claim clients and purge old cache versions
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event: Network-first for dynamic routes, cache-fallback for immutable static assets
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Only handle GET requests within same origin
    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // NEVER cache dynamic endpoints: authentication, sessions, API mutations, private pages
    const isDynamic = url.pathname.endsWith('.php') || 
                      url.pathname.includes('/api') || 
                      url.pathname === '/' || 
                      url.search.includes('action=');

    if (isDynamic) {
        // Direct network passthrough: always fresh from PHP server
        event.respondWith(
            fetch(request, { credentials: 'include' }).catch((err) => {
                return Promise.reject(err);
            })
        );
        return;
    }

    // Static assets (images, icons): Stale-while-revalidate or cache-first
    event.respondWith(
        caches.match(request).then((cachedResponse) => {
            if (cachedResponse) {
                // Fetch fresh copy in background to update cache
                fetch(request).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, networkResponse));
                    }
                }).catch(() => {});
                return cachedResponse;
            }
            return fetch(request).then((networkResponse) => {
                if (networkResponse && networkResponse.status === 200) {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
                }
                return networkResponse;
            });
        })
    );
});
