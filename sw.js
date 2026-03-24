// =============================================================================
//  sw.js — Service Worker pour les notifications push Badal
//
//  Stratégie : push sans payload → le SW fetch le contenu depuis le serveur.
//  Compatible Chrome, Firefox, Edge, Safari 16+.
// =============================================================================

self.addEventListener('push', function(event) {
  event.waitUntil(
    fetch('./push-notification.json')
      .then(function(r) { return r.ok ? r.json() : null; })
      .then(function(data) {
        if (!data || !data.title) {
          data = { title: 'Nouvel épisode disponible', body: '' };
        }
        return self.registration.showNotification(data.title, {
          body: data.body || '',
          icon: data.icon || '',
          badge: data.icon || '',
          data: { url: data.url || './' }
        });
      })
      .catch(function() {
        return self.registration.showNotification('Nouvel épisode disponible');
      })
  );
});

// Clic sur la notification → ouvrir l'URL
self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || './';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
      // Réutiliser un onglet existant si possible
      for (var i = 0; i < list.length; i++) {
        if (list[i].url.indexOf(url) !== -1 && 'focus' in list[i]) {
          return list[i].focus();
        }
      }
      return clients.openWindow(url);
    })
  );
});
