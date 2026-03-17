/**
 * admin/assets/onboarding.js
 * Tooltips pour Badal — apparaissent au survol des éléments [data-tip]
 */
(function () {
  'use strict';

  function initTooltips() {
    var tip = document.createElement('div');
    tip.id  = 'ob-tooltip';
    document.body.appendChild(tip);

    var hideTimer;

    document.querySelectorAll('[data-tip]').forEach(function(el) {
      el.addEventListener('mouseenter', function() {
        clearTimeout(hideTimer);
        tip.textContent = el.dataset.tip;
        tip.classList.add('visible');
        positionTip(el, tip);
      });
      el.addEventListener('mouseleave', function() {
        hideTimer = setTimeout(function() { tip.classList.remove('visible'); }, 100);
      });
      el.addEventListener('mousemove', function() {
        positionTip(el, tip);
      });
    });
  }

  function positionTip(el, tip) {
    var r   = el.getBoundingClientRect();
    var tw  = tip.offsetWidth  || 200;
    var th  = tip.offsetHeight || 32;
    var top = r.top - th - 8 + window.scrollY;
    var left = r.left + r.width / 2 - tw / 2 + window.scrollX;
    left = Math.max(8, Math.min(left, window.innerWidth - tw - 8));
    if (top < window.scrollY + 8) top = r.bottom + 8 + window.scrollY;
    tip.style.top  = top + 'px';
    tip.style.left = left + 'px';
  }

  document.addEventListener('DOMContentLoaded', initTooltips);
})();
