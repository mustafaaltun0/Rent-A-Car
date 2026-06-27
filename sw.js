const SW_SCOPE_PATH = new URL(self.location.href).pathname.replace(/\/sw\.js$/, '');
const APP_PREFIX = SW_SCOPE_PATH === '/' ? '' : SW_SCOPE_PATH;
const CACHE_NAME = 'rentecarweb-v2';
const OFFLINE_URLS = [
  `${APP_PREFIX}/index.php`,
  `${APP_PREFIX}/assets/css/style.css`,
  `${APP_PREFIX}/assets/js/main.js`,
  `${APP_PREFIX}/assets/icons/app-icon.svg`
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(OFFLINE_URLS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  const isAppRequest = url.origin === self.location.origin
    && (APP_PREFIX === '' ? true : url.pathname.startsWith(`${APP_PREFIX}/`));

  if (!isAppRequest) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
          return response;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match(`${APP_PREFIX}/index.php`)))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }

      return fetch(request).then((response) => {
        const responseClone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
        return response;
      });
    })
  );
});
