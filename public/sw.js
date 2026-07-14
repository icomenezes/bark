const CACHE_NAME = 'app-v1';
const STATIC_ASSETS = [
    '/',
    '/dashboard',
    '/manifest.json',
];

self.addEventListener('install', function (event) {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(STATIC_ASSETS).catch(function () {});
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (k) { return k !== CACHE_NAME; })
                    .map(function (k) { return caches.delete(k); })
            );
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    // Não cachear heartbeat, APIs ou rotas POST
    if (event.request.method !== 'GET') return;
    if (event.request.url.includes('/heartbeat') ||
        event.request.url.includes('/api/')) return;

    event.respondWith(
        fetch(event.request).catch(function () {
            return caches.match(event.request);
        })
    );
});
