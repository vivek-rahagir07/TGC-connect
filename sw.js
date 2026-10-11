/**
 * TGC Connect - Progressive Web App Service Worker
 * Version: 1.0.0
 */

const CACHE_NAME = 'tgc-connect-v1.0.0';

const PRECACHE_ASSETS = [
  './',
  './index.html',
  './login.html',
  './portal.html',
  './admin.html',
  './mark_attendance.html',
  './gps_punch.html',
  './qr_scanner.html',
  './register.html',
  './css/style.css',
  './js/app.js',
  './js/calendar.js',
  './manifest.json',
  './assets/logo.png',
  './assets/logo_icon.png',
  './assets/favicon.ico',
  './assets/icons/icon-192x192.png',
  './assets/icons/icon-512x512.png',
  './assets/icons/apple-touch-icon.png'
];

// Install: Cache core application shell
self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      console.log('[TGC PWA ServiceWorker] Pre-caching core application shell');
      // Add assets individually so one optional file failure doesn't abort worker installation
      const promises = PRECACHE_ASSETS.map(url => {
        return cache.add(url).catch(err => {
          console.warn('[TGC PWA ServiceWorker] Skipping optional asset:', url, err);
        });
      });
      return Promise.all(promises);
    })
  );
});

// Activate: Clean up previous cache versions and claim clients
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((name) => {
          if (name !== CACHE_NAME) {
            console.log('[TGC PWA ServiceWorker] Purging outdated cache:', name);
            return caches.delete(name);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch Strategy:
// 1. Non-GET: Network only (API POST / auth / punches)
// 2. /api/ calls: Network-first with graceful offline fallback
// 3. Navigation / HTML pages: Network-first, fallback to cache
// 4. Static assets (CSS/JS/images/fonts): Stale-while-revalidate for blazing speed
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Ignore non-GET requests (e.g. POST/PUT/DELETE for attendance punch, login)
  if (request.method !== 'GET') {
    return;
  }

  // Handle API Requests (Network-First)
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // If valid response and not an action requiring strict no-cache, clone to cache
          if (response && response.status === 200) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          }
          return response;
        })
        .catch(async () => {
          // Try to return from cache if offline
          const cached = await caches.match(request);
          if (cached) return cached;

          // Fallback JSON for offline API
          return new Response(
            JSON.stringify({
              success: false,
              offline: true,
              message: 'You are currently working offline. Connection will restore when internet returns.'
            }),
            {
              status: 503,
              headers: { 'Content-Type': 'application/json' }
            }
          );
        })
    );
    return;
  }

  // Handle HTML Page Navigations (Network-First with Cache Fallback)
  if (request.mode === 'navigate' || (request.headers.get('accept') && request.headers.get('accept').includes('text/html'))) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response && response.status === 200) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          }
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(request);
          if (cached) return cached;
          // Fallback to portal or index if cached
          const portalCached = await caches.match('./portal.html');
          if (portalCached) return portalCached;
          const indexCached = await caches.match('./index.html');
          if (indexCached) return indexCached;
          return new Response('<h3>TGC Connect is offline. Please check your internet connection.</h3>', {
            status: 503,
            headers: { 'Content-Type': 'text/html' }
          });
        })
    );
    return;
  }

  // Static Assets: Stale-While-Revalidate
  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      const fetchPromise = fetch(request)
        .then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            const clone = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          }
          return networkResponse;
        })
        .catch(() => null);

      return cachedResponse || fetchPromise;
    })
  );
});

// Support manual reload messages
self.addEventListener('message', (event) => {
  if (event.data && event.data.action === 'skipWaiting') {
    self.skipWaiting();
  }
});
