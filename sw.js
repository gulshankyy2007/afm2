/**
 * AFM#2 - Progressive Web App Service Worker
 * Ensures standalone installability on Android/Chrome (WebAPK)
 * while preserving live, dynamic server-side session and tournament integrity.
 */

const CACHE_NAME = 'afm2-pwa-v1';

// Install event: activate immediately without waiting for existing tabs to close
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

// Activate event: claim all connected clients immediately
self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// Fetch event: Network-first passthrough for all navigation and API requests
// Ensures dynamic PHP authentication, sessions, and tournament scorecards are always fresh
self.addEventListener('fetch', (event) => {
    event.respondWith(
        fetch(event.request).catch((error) => {
            return Promise.reject(error);
        })
    );
});
