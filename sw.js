const CACHE_NAME = 'access-portal-static-v1';
const basePath = new URL(self.registration.scope).pathname.replace(/\/$/, '');
const asset = path => `${basePath}/${path}`;
const STATIC_ASSETS = [
  asset('assets/app.css'),
  asset('assets/rich.js'),
  asset('assets/pwa-register.js'),
  asset('assets/favicon.svg'),
  asset('assets/pwa-icon-192.png'),
  asset('assets/pwa-icon-512.png'),
  asset('manifest.webmanifest'),
  asset('offline.html'),
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('access-portal-') && key !== CACHE_NAME).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin) return;
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match(asset('offline.html'))));
    return;
  }
  if (STATIC_ASSETS.includes(url.pathname)) {
    event.respondWith(caches.match(request, {ignoreSearch: true}).then(cached => cached || fetch(request).then(response => {
      const copy = response.clone();
      caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
      return response;
    })));
  }
});
