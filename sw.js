const CACHE = 'gestione-ticket-v1';
const STATIC = [
    '/manifest.json',
    '/style.css',
    '/app.js',
    '/assets/icon-192.png',
    '/assets/icon-512.png',
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE).then(c => c.addAll(STATIC).catch(() => {}))
    );
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        ).then(() => clients.claim())
    );
});

self.addEventListener('fetch', e => {
    const req = e.request;

    // Ignora completamente richieste POST (chiamate API dell'app)
    if (req.method !== 'GET') return;

    // Ignora richieste verso altri domini
    if (!req.url.startsWith(self.location.origin)) return;

    const url = new URL(req.url);
    const isStatic = STATIC.some(s => url.pathname === s);

    if (isStatic) {
        // Cache first per asset statici
        e.respondWith(
            caches.match(req).then(cached => cached || fetch(req))
        );
    } else {
        // Network first per tutto il resto
        e.respondWith(
            fetch(req).catch(() => caches.match(req))
        );
    }
});
