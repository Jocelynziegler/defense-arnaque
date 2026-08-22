/**
 * sw.js — Service Worker de l'application Ziegler Alerte Arnaque
 * ---------------------------------------------------------------------
 * Deux rôles :
 * 1. Mise en cache légère de la coquille de l'app pour un chargement
 *    rapide et une tolérance aux coupures réseau ponctuelles.
 * 2. Réception et affichage des notifications push (nouvelles alertes,
 *    publications du cabinet, activité du forum).
 *
 * IMPORTANT : les données elles-mêmes (liste noire, alertes, forum) ne
 * sont JAMAIS mises en cache de façon agressive — un visiteur qui vérifie
 * une société doit toujours voir l'information la plus fraîche possible.
 * Seule la coquille de l'app (HTML de base, icônes) est mise en cache.
 * ---------------------------------------------------------------------
 */

const CACHE_NAME = 'ziegler-app-shell-v1';
const APP_SHELL = [
  '/app.html',
  '/manifest.json',
  '/assets/android-chrome-192x192.png',
  '/assets/android-chrome-512x512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) =>
      Promise.all(
        names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n))
      )
    )
  );
  self.clients.claim();
});

// Stratégie réseau d'abord (données toujours fraîches en priorité),
// avec repli sur le cache uniquement en cas de coupure réseau —
// et seulement pour la coquille de l'app (jamais les données métier
// comme la liste noire ou les alertes, qui ne sont pas dans APP_SHELL).
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  const isAppShell = APP_SHELL.some((path) => event.request.url.endsWith(path));
  if (!isAppShell) return; // laisse passer normalement, pas de mise en cache

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        const clone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});

// ---------------------------------------------------------------------
// NOTIFICATIONS PUSH
// ---------------------------------------------------------------------

self.addEventListener('push', (event) => {
  let payload = { title: 'Ziegler Alerte Arnaque', body: 'Nouvelle alerte disponible.', url: '/app.html' };
  try {
    if (event.data) payload = { ...payload, ...event.data.json() };
  } catch (e) {
    // Si le contenu n'est pas du JSON valide, on garde les valeurs par defaut.
  }

  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: payload.body,
      icon: '/assets/android-chrome-192x192.png',
      badge: '/assets/android-chrome-192x192.png',
      data: { url: payload.url || '/app.html' },
      tag: payload.tag || 'ziegler-alerte',
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data && event.notification.data.url ? event.notification.data.url : '/app.html';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientsArr) => {
      const existing = clientsArr.find((c) => c.url.includes(targetUrl));
      if (existing) return existing.focus();
      return self.clients.openWindow(targetUrl);
    })
  );
});
