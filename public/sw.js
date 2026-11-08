// Service worker του Knowledge: κάνει το site εγκαταστάσιμη εφαρμογή.
// Κρατάμε στη μνήμη ΜΟΝΟ στατικά αρχεία (στυλ, εικονίδια, σελίδα «χωρίς σύνδεση»).
// Τις σελίδες με προσωπικά δεδομένα δεν τις αποθηκεύουμε ποτέ.
const CACHE = 'knowledge-v1';
const STATIC = ['style.css', 'scanner.js', 'offline.html', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/favicon-64.png'];

self.addEventListener('install', function (event) {
    event.waitUntil(caches.open(CACHE).then(function (cache) { return cache.addAll(STATIC); }));
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }));
    self.clients.claim();
});

self.addEventListener('fetch', function (event) {
    const request = event.request;
    if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) {
        return;
    }
    if (request.mode === 'navigate') {
        // Σελίδες: πάντα από το internet· αν δεν υπάρχει σύνδεση, η σελίδα «χωρίς σύνδεση»
        event.respondWith(fetch(request).catch(function () { return caches.match('offline.html'); }));
        return;
    }
    const path = new URL(request.url).pathname.split('/').pop();
    if (STATIC.some(function (file) { return file.endsWith(path) && path !== ''; })) {
        // Στατικά: πρώτα από το internet (για να παίρνουμε τις αλλαγές), αλλιώς από τη μνήμη
        event.respondWith(fetch(request).then(function (response) {
            const copy = response.clone();
            caches.open(CACHE).then(function (cache) { cache.put(request, copy); });
            return response;
        }).catch(function () { return caches.match(request); }));
    }
});
