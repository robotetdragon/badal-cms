// =============================================================================
//  push.js — Client-side Web Push subscription
//
//  Usage: include in public pages with:
//    <script src="push.js" data-vapid="<base64url public key>"
//            data-subscribe-url="./push-subscribe"></script>
//
//  Displays a bell button if the browser supports push notifications.
// =============================================================================

(function() {
  'use strict';

  // Check support
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    return;
  }

  var script = document.currentScript;
  if (!script) return;

  var vapidKey     = script.getAttribute('data-vapid');
  var subscribeUrl = script.getAttribute('data-subscribe-url');
  if (!vapidKey || !subscribeUrl) return;

  // Register Service Worker
  navigator.serviceWorker.register('./sw.js').then(function(reg) {
    // Wait for SW to be ready
    return navigator.serviceWorker.ready;
  }).then(function(reg) {
    // Check current subscription state
    return reg.pushManager.getSubscription().then(function(sub) {
      renderBell(reg, sub);
    });
  }).catch(function() {
    // SW registration failed — don't show bell
  });

  function renderBell(reg, currentSub) {
    var btn = document.createElement('button');
    btn.className = 'push-bell' + (currentSub ? ' push-bell--active' : '');
    btn.setAttribute('aria-label', currentSub ? 'Notifications activées' : 'Activer les notifications');
    btn.title = btn.getAttribute('aria-label');
    btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
      + '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>'
      + '<path d="M13.73 21a2 2 0 0 1-3.46 0"/>'
      + '</svg>';

    btn.addEventListener('click', function() {
      if (Notification.permission === 'denied') {
        alert('Les notifications sont bloquées. Autorisez-les dans les paramètres du navigateur.');
        return;
      }

      if (currentSub) {
        // Unsubscribe
        currentSub.unsubscribe().then(function() {
          fetch(subscribeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'unsubscribe', endpoint: currentSub.endpoint })
          });
          currentSub = null;
          btn.classList.remove('push-bell--active');
          btn.setAttribute('aria-label', 'Activer les notifications');
          btn.title = btn.getAttribute('aria-label');
          showToast('Notifications désactivées');
        });
      } else {
        // Subscribe
        var applicationServerKey = urlBase64ToUint8Array(vapidKey);
        reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: applicationServerKey
        }).then(function(sub) {
          currentSub = sub;
          var subJson = sub.toJSON();
          return fetch(subscribeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'subscribe', subscription: subJson })
          });
        }).then(function() {
          btn.classList.add('push-bell--active');
          btn.setAttribute('aria-label', 'Notifications activées');
          btn.title = btn.getAttribute('aria-label');
          showToast('Notifications activées !');
        }).catch(function(err) {
          if (Notification.permission === 'denied') {
            alert('Les notifications sont bloquées par le navigateur.');
          }
        });
      }
    });

    // Insert bell next to share button (same container, same line)
    var target = document.querySelector('.ep-share-btn') || document.querySelector('.share-btn');
    if (target) {
      target.parentNode.insertBefore(btn, target.nextSibling);
    }
  }

  function showToast(msg) {
    var t = document.getElementById('share-toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'share-toast';
      t.className = 'share-toast';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2500);
  }

  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = atob(base64);
    var array = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; i++) {
      array[i] = rawData.charCodeAt(i);
    }
    return array;
  }
})();
