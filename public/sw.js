// HookPool Service Worker — minimal, required for PWA installability
const CACHE = 'hookpool-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

// Network-first: always fetch fresh, no offline caching needed
self.addEventListener('fetch', e => {
    e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
});
