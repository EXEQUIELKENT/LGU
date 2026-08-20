/**
 * sw.js — minimal service worker for InfraGovServices' PWA installability.
 *
 * Deliberately does NOT cache anything or serve offline content — this app's
 * data (report status, maps, DPWH road data) is only ever correct when
 * fresh, and a caching strategy can't be properly verified without HTTPS
 * (this deploys on plain HTTP during local/XAMPP development). Every fetch
 * just passes straight through to the network.
 *
 * Registering a service worker with a fetch handler is still what makes the
 * browser consider the site "installable" (Add to Home Screen / desktop
 * install), which is the actual goal here. Offline caching can be added
 * later once the site is served over HTTPS and can be tested end-to-end.
 */

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  event.respondWith(fetch(event.request));
});
