// =============================================================================
//  sw.js — Service Worker for Badal push notifications
//
//  Strategy: push without payload → the SW fetches content from the server.
//  Compatible with Chrome, Firefox, Edge, Safari 16+.
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

// Click on the notification → open the URL
self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || './';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
      // Reuse an existing tab if possible
      for (var i = 0; i < list.length; i++) {
        if (list[i].url.indexOf(url) !== -1 && 'focus' in list[i]) {
          return list[i].focus();
        }
      }
      return clients.openWindow(url);
    })
  );
});
