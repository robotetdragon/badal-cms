// Preset buttons removed — themes are now managed as separate JSON files in themes/

// Color inputs — oninput replaced by data attributes
document.querySelectorAll('[data-color]').forEach(function(picker) {
  picker.addEventListener('input', function() {
    syncColor(picker.dataset.color, picker.value);
  });
});
document.querySelectorAll('[data-sync]').forEach(function(input) {
  input.addEventListener('input', function() {
    syncColorPicker(input.dataset.sync, input.value);
  });
});

// Font selects — font + weight
document.querySelectorAll('[data-font-key]').forEach(function(sel) {
  sel.addEventListener('change', function() {
    const key   = sel.dataset.fontKey;
    const font  = sel.value;
    const wSel  = document.querySelector('[data-weight-for="' + key + '"]');
    const weight = wSel ? wSel.value : '400';
    updateFontPreview(key, font, weight);
  });
});

// Weight selects
document.querySelectorAll('[data-weight-for]').forEach(function(sel) {
  sel.addEventListener('change', function() {
    const key   = sel.dataset.weightFor;
    const fSel  = document.getElementById('sel_' + key);
    const font  = fSel ? fSel.value : '';
    updateFontPreview(key, font, sel.value);
  });
});

// Font preview spans — data-font + data-weight
document.querySelectorAll('[data-font]').forEach(function(span) {
  span.style.fontFamily = "'" + span.dataset.font + "'";
  if (span.dataset.weight) span.style.fontWeight = span.dataset.weight;
});

// Alert classes from data-type
document.querySelectorAll('.alert[data-type]').forEach(function(el) {
  el.classList.add('alert-' + el.dataset.type);
});

// ── Color sync ──
function syncColor(key, val) {
  document.getElementById('ct_' + key).value = val;
}
function syncColorPicker(key, val) {
  if (/^#[0-9a-fA-F]{3,8}$/.test(val)) {
    document.getElementById('cp_' + key).value = val;
    }
}
function applyPreset(bg, surf, bord, acc, txt, mut) {
  const map = {color_bg:bg, color_surface:surf, color_border:bord, color_accent:acc, color_text:txt, color_muted:mut};
  for (const [k, v] of Object.entries(map)) {
    var cp = document.getElementById('cp_' + k);
    var ct = document.getElementById('ct_' + k);
    if (cp) cp.value = v;
    if (ct) ct.value = v;
  }
}

// ── Font preview ──
const googleFontsCache = new Set();
function updateFontPreview(key, font, weight) {
  weight = weight || '400';
  loadGoogleFont(font, weight);
  const el = document.getElementById('prev_' + key + '_text');
  if (el) {
    el.style.fontFamily  = "'" + font + "'";
    el.style.fontWeight  = weight;
    el.dataset.weight    = weight;
  }
  updateComboPreview();
}
function loadGoogleFont(font, weight) {
  const cacheKey = font + ':' + weight;
  if (googleFontsCache.has(cacheKey)) return;
  googleFontsCache.add(cacheKey);
  const link = document.createElement('link');
  link.rel  = 'stylesheet';
  // Load all common weights for this display
  link.href = 'https://fonts.googleapis.com/css2?family='
            + encodeURIComponent(font)
            + ':wght@300;400;500;600;700;800;900&display=swap';
  document.head.appendChild(link);
}
function updateComboPreview() {
  const hSel = document.getElementById('sel_font_heading');
  const bSel = document.getElementById('sel_font_body');
  const hwSel = document.querySelector('[data-weight-for="font_heading"]');
  const bwSel = document.querySelector('[data-weight-for="font_body"]');
  const hEl = document.getElementById('combo-heading');
  const bEl = document.getElementById('combo-body');
  if (hSel && hEl) {
    hEl.style.fontFamily = "'" + hSel.value + "'";
    hEl.style.fontWeight = hwSel ? hwSel.value : '800';
  }
  if (bSel && bEl) {
    bEl.style.fontFamily = "'" + bSel.value + "'";
    bEl.style.fontWeight = bwSel ? bwSel.value : '400';
  }
}

// ── Tabs ──
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
  });
});

// ── Drag & drop sections ──
let dragEl = null;
document.querySelectorAll('.section-item').forEach(item => {
  item.setAttribute('draggable', 'true');
  item.addEventListener('dragstart', e => { dragEl = item; item.classList.add('dragging'); });
  item.addEventListener('dragend',   e => { dragEl = null; item.classList.remove('dragging'); });
  item.addEventListener('dragover',  e => {
    e.preventDefault();
    if (!dragEl || dragEl === item) return;
    const rect = item.getBoundingClientRect();
    const after = e.clientY > rect.top + rect.height / 2;
    item.parentNode.insertBefore(dragEl, after ? item.nextSibling : item);
  });
});

// Live preview removed

// Init font previews
document.querySelectorAll('[id^="sel_font"]').forEach(sel => {
  loadGoogleFont(sel.value);
});
updateComboPreview();

// Restore enctype for file upload
const appForm = document.getElementById('appearance-form');
if (appForm) appForm.setAttribute('enctype', 'multipart/form-data');

// ── Autosave ──────────────────────────────────────────────────────────────────
(function() {
  var form = document.getElementById('appearance-form');
  var status = document.getElementById('autosave-status');
  if (!form || !status) return;

  var saveTimer = null;
  var DEBOUNCE = 800; // ms after the last change

  var CHECK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#5aff9a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
  var SPIN_SVG  = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation:spin .8s linear infinite"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></svg><style>@keyframes spin{to{transform:rotate(360deg)}}</style>';
  var ERR_SVG   = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#ff5a5a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';

  function setStatus(icon, text, color) {
    status.innerHTML = icon + '<span style="color:' + color + '">' + text + '</span>';
  }

  var currentController = null;

  function doSave() {
    // Cancel the previous save if still in progress
    if (currentController) currentController.abort();
    currentController = new AbortController();

    setStatus(SPIN_SVG, 'Saving…', 'var(--muted)');
    var data = new FormData(form);
    data.append('_ajax', '1');
    fetch(form.action || window.location.href, {
      method: 'POST',
      body: data,
      signal: currentController.signal,
    })
    .then(function(r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(function(json) {
      currentController = null;
      if (json.ok) {
        setStatus(CHECK_SVG, 'Saved', '#5aff9a');
        setTimeout(function() { status.innerHTML = ''; }, 2500);
      } else {
        setStatus(ERR_SVG, 'Error', '#ff5a5a');
      }
    })
    .catch(function(err) {
      if (err && err.name === 'AbortError') return; // navigation, not an error
      setStatus(ERR_SVG, 'Network error', '#ff5a5a');
    });
  }

  function schedSave() {
    clearTimeout(saveTimer);
    setStatus('', 'Modification in progress…', 'var(--muted)');
    saveTimer = setTimeout(doSave, DEBOUNCE);
  }

  // Listen to all form fields
  form.addEventListener('input',  schedSave);
  form.addEventListener('change', schedSave);

  // Theme cards are managed via form submission, no autosave needed for them

  // Drag & drop sections
  var sectionsContainer = document.querySelector('.sections-list');
  if (sectionsContainer) {
    var obs = new MutationObserver(function(mutations) {
      mutations.forEach(function(m) {
        if (m.type === 'childList') schedSave();
      });
    });
    obs.observe(sectionsContainer, { childList: true });
  }

  // Prevent normal form submission
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    doSave();
  });
})();
