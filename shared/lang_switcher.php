<?php
/**
 * shared/lang_switcher.php
 * Widget de sélection de langue — flottant, coin bas-gauche.
 * À inclure dans layout_head.php (admin) et dans les pages publiques.
 */
$_currentLang  = Lang::current();
$_langLabels   = Lang::LABELS;
$_langSupported = Lang::SUPPORTED_LANGS;

// URL de base pour switcher (ajoute ?lang=xx à l'URL courante sans le fragment)
$_switchBase = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
?>
<div class="lang-switcher" id="langSwitcher">
  <button class="lang-switcher__btn" onclick="document.getElementById('langSwitcher').classList.toggle('open')" aria-label="Changer de langue">
    <?= $_langLabels[$_currentLang] ?? $_currentLang ?>
    <i data-feather="chevron-down" class="lang-switcher__arrow" style="width:11px;height:11px;stroke-width:2.5"></i>
  </button>
  <ul class="lang-switcher__menu" role="listbox">
    <?php foreach ($_langSupported as $_code): ?>
      <li>
        <a href="<?= htmlspecialchars($_switchBase . '?lang=' . $_code, ENT_QUOTES, 'UTF-8') ?>"
           role="option"
           <?= $_code === $_currentLang ? 'aria-selected="true"' : '' ?>>
          <?= $_langLabels[$_code] ?? $_code ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<style>
.lang-switcher {
  position: fixed;
  bottom: 20px;
  right: 20px;
  z-index: 9999;
  font-family: inherit;
}
@media (max-width: 640px) {
  .lang-switcher { bottom: 70px; right: 12px; }
}
.lang-switcher__btn {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(22, 22, 26, 0.92);
  color: #c8c4be;
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  padding: 7px 12px;
  font-size: 0.78rem;
  font-weight: 500;
  cursor: pointer;
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  transition: background 0.15s, color 0.15s, border-color 0.15s;
  white-space: nowrap;
}
.lang-switcher__btn:hover {
  background: rgba(40, 40, 46, 0.98);
  color: #f0ede8;
  border-color: rgba(255,255,255,0.2);
}
.lang-switcher__arrow {
  transition: transform 0.2s;
  opacity: 0.6;
  flex-shrink: 0;
}
.lang-switcher.open .lang-switcher__arrow {
  transform: rotate(180deg);
}
.lang-switcher__menu {
  display: none;
  position: absolute;
  bottom: calc(100% + 6px);
  right: 0;
  background: rgba(22, 22, 26, 0.97);
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 8px;
  list-style: none;
  padding: 4px;
  margin: 0;
  min-width: 140px;
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  box-shadow: 0 8px 24px rgba(0,0,0,0.4);
}
.lang-switcher.open .lang-switcher__menu {
  display: block;
}
.lang-switcher__menu li a {
  display: block;
  padding: 8px 12px;
  color: #c8c4be;
  text-decoration: none;
  font-size: 0.82rem;
  border-radius: 5px;
  transition: background 0.12s, color 0.12s;
}
.lang-switcher__menu li a:hover {
  background: rgba(255,255,255,0.07);
  color: #f0ede8;
}
.lang-switcher__menu li a[aria-selected="true"] {
  color: #e8ff5a;
  font-weight: 600;
}
</style>

<script>
// Fermer en cliquant ailleurs
if (window.feather) feather.replace({'stroke-width':2});
document.addEventListener('click', function(e) {
  var sw = document.getElementById('langSwitcher');
  if (sw && !sw.contains(e.target)) sw.classList.remove('open');
});
</script>
