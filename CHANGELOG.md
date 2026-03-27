# Changelog

All notable changes to Badal are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [0.6] — 2026-03-27

### Added
- **AJAX import** — import process rewritten from a single monolithic PHP request to an AJAX-driven architecture (one HTTP request per episode). Supports podcasts with 100+ episodes without timeout. Progress bar, elapsed timer, per-episode logs, and automatic resume if a file already exists on disk
- **Import completion popup** — animated popup after import with step-by-step RSS feed redirect instructions (same style as the publish animation)
- **Feed redirect guide** — detailed 6-step procedure in the Tools page explaining how to migrate a podcast without losing subscribers (`itunes:new-feed-url` + `podcast:previousUrl`)
- **Telemetry: country detection** — the telemetry server now resolves the country from the requester's IP via ip-api.com (cached locally, no data sent from the CMS). New "Répartition par pays" distribution chart and country column in the dashboard
- **Telemetry opt-in by default** — new installations have anonymous telemetry enabled by default (can be disabled in account settings)
- **Security dashboard** — documented in README: real-time audit of HTTP headers, file permissions, auth log, rate-limit status
- **Password reset** — documented in README: email-based recovery with hashed tokens (30 min expiry)
- **UI: enhanced visual feedback** — `:active` press states, `:focus-visible` outlines, hover glow on buttons, card lift on hover, input focus ring, stat card hover, episode row indent, nav link animations, custom scrollbar, alert entrance animation. Applied across all admin and public pages

### Fixed
- **Import: episode count bug** — `count((array)$channel->item)` counted XML child elements of the first item (typically 6) instead of the total number of episodes. Fixed to use `count($epList)` after extraction. This caused imports to silently stop after a few episodes
- **Import: output buffer corruption** — AJAX responses could contain PHP warnings or bootstrap HTML due to `ob_start()` not being cleared. Added `ob_end_clean()` before each JSON response
- **Import: slugify fallback** — added `iconv` fallback when the `intl` PHP extension is not available, preventing fatal errors on `transliterator_transliterate`
- **Config write escaping** — `importWriteKey`, `toolsWriteKey`, `podWriteKey`, and `writeConfigKey` now properly escape backslashes before apostrophes (`str_replace(['\\', "'"], ['\\\\', "\\'"])`) for PHP single-quoted strings. Previously, podcast titles containing apostrophes (e.g. "L'heure du crime") could corrupt `config.php`
- **Config write regex** — `writeConfigKey` in `account.php` now uses `'(?:[^'\\\\]|\\\\.)*'` pattern to match values that already contain escaped quotes, instead of `'[^']*'` which failed on renamed podcasts

### Changed
- **Show notes editor** — replaced EasyMDE rich editor with a plain `textarea.transcript-area` (monospace, 600px min-height), matching the transcript field style for consistency and full-width display
- **Codebase cleanup** — all 35+ PHP/JS files reformatted: 4-space indentation, `═══` section dividers, PHPDoc on all public methods, aligned variables, consistent brace style, CSS organized by section. No logic changes
- **README updated** — added 0.6 changelog, 3 new features (security dashboard, password reset, accurate listen stats), complete `core/` file listing with descriptions, expanded public URLs table, expanded security table

---

## [0.52] — 2026-03-26

### Fixed
- **Inflated listen stats** — listen counting moved from the audio proxy (`audio.php`) to a dedicated endpoint (`stats-record.php`) triggered by JavaScript on actual play events. Previously, every page visit triggered a count via the browser's `preload="metadata"` request. Bots and crawlers no longer inflate stats
- **IP deduplication** — max 1 listen per IP per episode per 24-hour window, preventing repeated counts from the same listener. Deduplication files are automatically purged

### Changed
- **Show notes editor** — EasyMDE Markdown editor height increased from 340px to 600px in both episode creation and edit pages, giving more room to write show notes

---

## [0.51] — 2026-03-26

### Fixed
- **Import XSS** — import progress logs are now HTML-escaped to prevent XSS via malicious RSS feed titles
- **Import crash on malformed XML** — `libxml_get_last_error()` returning `false` no longer causes a fatal error
- **Import slug collisions** — episodes with duplicate titles now receive a numeric suffix instead of overwriting each other
- **RSS feed: empty description** — imported episodes now include a `description` field in YAML frontmatter, fixing empty `<description>` tags in the generated feed
- **RSS feed: content:encoded** — `<content:encoded>` now uses the full show notes HTML instead of duplicating the short description
- **RSS feed: per-episode explicit** — `<itunes:explicit>` now reads each episode's `explicit` frontmatter field instead of being hardcoded to `false`
- **Cover import fallback** — added `media:thumbnail` and `media:content` fallback (Spotify, Audioboom, etc.) when `itunes:image` is missing

### Changed
- **Episode ordering on import** — import now resets custom order (`episodes_order.json`) so episodes sort by publication date
- **Dynamic .htaccess** — `setup.php` generates `.htaccess` with the correct `RewriteBase` derived from the detected `base_url`, fixing broken episode links and missing cover images on non-`/badal/` deployments

---

## [0.5] — 2026-03-26

### Added
- **Draft episodes** — episodes can be saved as drafts before publishing. New `status` field in YAML frontmatter (`draft` / `published`). Draft episodes are hidden from the public site, RSS feed, and sitemap. "BROUILLON" badge displayed in the admin episode list and edit page. Dual save buttons in editor: "Sauvegarder" (keep as draft) and "Publier" (go live). Published episodes can be reverted to draft
- **Email social link** — new Email field in social networks settings (`social_email` in `home.json`). Renders as a `mailto:` link with an envelope icon on the public homepage
- **Sticky table headers** — `thead` is now `position: sticky` with `background: var(--surface)` so column headers remain visible when scrolling long episode lists

### Changed
- **Mobile episode cards** — episode list on the homepage switches from horizontal rows to a vertical 2-column card grid on screens < 768px. Covers become full-width with `aspect-ratio: 1/1`, play button overlays the cover. Same treatment applied to "other episodes" on the episode page, with further refinement at 400px
- **Mobile episode page** — cover image scales to full-width below 560px, progress bar hidden on very small screens
- **Mobile sidebar** — sidebar now scrolls independently (`overflow-y: auto`) and nav section no longer forces flex layout, preventing content from being cut off on short screens
- **Mobile account page** — account settings grid collapses to single column on small screens
- **Mobile table scroll** — `.table-wrap` now scrolls both axes with `max-height: calc(100dvh - 120px)` for contained table viewing on mobile
- **EpisodeParser::getAll()** — new `$includeDrafts` parameter (default `false`). Admin pages pass `true` to see all episodes; public pages use the default to exclude drafts
- **Publish animation skip** — saving as draft bypasses the publish popup animation

---

## [0.4] — 2026-03-25

### Added
- **Unit tests** — PHPUnit 9.6 test suite with 107 tests and 226 assertions covering all core classes: `EpisodeParser`, `StatsManager`, `ThemeManager`, `HomeManager`, `ChaptersManager`, `TranscriptManager`, `RssGenerator`, `AudioDuration`, `Lang`
- **Composer setup** — `composer.json` with autoload classmap on `core/`, PHPUnit as dev dependency
- **Docker test support** — tests runnable inside the existing Docker container

### Fixed
- **YAML frontmatter escaping** — replaced `addslashes()` with proper YAML double-quote escaping in `EpisodeParser::yamlValue()`, fixing backslash artifacts (`\'`) in episode descriptions containing colons or special characters
- **Update popup link** — `version.json` URL now points to the correct GitHub repository instead of a non-existent release tag

### Changed
- **English codebase** — all code comments (PHP, JS, HTML, CSS) translated from French to English across 52 source files
- **Stats default period** — statistics page now defaults to 7-day view instead of 30 days
- **Mobile stats KPIs** — episode header KPIs (total plays, period plays, engagement ring) wrap to a new line below cover image and title on screens < 640px
- **Sidebar footer order** — reordered to: My Account, GitHub, Logout

---

## [0.3] — 2026-03-24

### Added
- **Chapters (Podcasting 2.0)** — add timestamped chapters to episodes, served as JSON for compatible players. New `ChaptersManager` class and `public/chapters.php` endpoint
- **Push notifications** — Web Push (VAPID) support. Listeners can subscribe from the public page, admin can send notifications when a new episode is published. New `WebPush` class, `sw.js` service worker, `admin/push.php` management page
- **Docker Compose** — full development environment with PHP 8.2 + Apache + ffmpeg. Demo podcast "Limites" seeded on first run. Live-mount source code for hot-reload
- **Publish animation** — animated popup when creating a new episode: Badal micro SVG draws progressively with trailing effect, then "L'épisode est en ligne" fades in
- **Stats auto-backup** — `stats.json` is backed up on every write; automatically restored if the main file is missing after a server update
- **Episode covers in edit** — cover image upload and preview on the episode edit page

### Fixed
- **Docker RewriteBase** — Dockerfile `sed` now matches any subdirectory name (was hardcoded to `/betapodcast/`)
- **Episode delete** — added cover image and chapters cleanup on episode deletion

### Changed
- **RSS feed** — added `podcast:chapters` tag for episodes with chapters
- **Episode page** — chapters displayed as clickable timeline with seek-to-timestamp
- **Router** — new routes for `/chapters/{slug}.json`, `/push-subscribe`, `/push-notification.json`
- **Sidebar** — added push notifications link in admin navigation
- **Language files** — new keys for chapters, push notifications and publish animation in all 4 languages (FR, EN, ES, PT)

---

## [0.2] — 2026-03-23

### Added
- **Theme system** — visual themes stored as individual JSON files in `themes/`. Create, duplicate, switch and delete themes from the admin UI. Ships with 5 presets: Sombre, Nuit bleue, Minuit vert, Sable, Papier
- **Home config separation** — homepage settings (tagline, CTA, socials, logo, cover, layout) moved from `theme.json` to `config/home.json`, managed by new `HomeManager` class
- **Episode reordering** — drag-and-drop on the episodes page to set custom order. Saved in `config/episodes_order.json`. RSS feed, homepage and sitemap respect the new order automatically. Reset button to revert to date-based sorting
- **SEO / structured data** — JSON-LD on homepage (`WebSite`, `ItemList`, `BreadcrumbList`) and episode pages (`Article` with `AudioObject`, `BreadcrumbList`). `<meta name="generator">` tag on all public pages
- **RSS enhancements** — Google Play namespace (`googleplay:author`, `googleplay:description`, `googleplay:category`), Podcasting 2.0 namespace (`podcast:locked`), `<generator>` tag with version, per-episode `<itunes:image>` when cover is present
- **Feed redirect** — `itunes:new-feed-url` and `podcast:previousUrl` support for migrating a podcast without losing subscribers. Configurable from the tools page with activation/deactivation controls
- **Tools page** — new dedicated `admin/tools.php` with feed redirect, ZIP export and podcast deletion (moved from podcast settings)
- **Update popup** — `Version::check()` called on all admin pages via `layout_head.php`. Modal popup with version comparison, release notes and GitHub link. Dismissable per session via `sessionStorage`
- **Sidebar improvements** — "View site" link moved to top of navigation, GitHub link added to sidebar footer

### Changed
- **ThemeManager** refactored — now loads from `themes/` directory, supports `listThemes()`, `save()`, `delete()`, `loadActive()` and `loadFromArray()`
- **Admin style page** — new "Themes" tab with visual theme selector (color preview cards), replaced hardcoded JS presets
- **RSS builder layout** — Apple Podcasts validation section moved above metadata. Metadata and live RSS preview in responsive two-column grid
- **About page** — 5 new features listed (themes, reordering, SEO, redirect, tools). Version now uses `Version::CURRENT`
- **Language files** — 10 new keys added to all 4 languages (FR, EN, ES, PT) for new about features
- **Sidebar footer text** — matches nav link size (`.84rem`, weight 600)
- **Mobile sidebar** — increased link padding for better touch targets
- **Tools ZIP button** — responsive layout with `auto-fit minmax(280px)`
- **layout_head.php** — removed duplicate template (943 → 556 lines), added update popup styles and logic
- **Header overlay** — increased opacity from `.25` to `.7` for better text readability over cover images

---

## [0.13] — 2026-03-20

### Added
- **Share button** — Web Share API on mobile, clipboard copy on desktop (home + episode pages)
- **Open Graph & Twitter Card** — link previews with podcast cover / episode cover for social sharing
- **Social networks** — added Website, LinkedIn, TikTok and Pocket Casts to the social links settings
- **Public page translations** — all hardcoded French text on home, episode and about pages now uses the i18n system (fr, en, es, pt)

### Fixed
- **Telemetry: silent failure** — `asyncPost` now uses cURL (with `file_get_contents` fallback) instead of unreliable `fsockopen`; `last_sent` is only updated on successful send
- **Telemetry: stale episode counts** — dashboard no longer displays episode counts for installations inactive for more than 24 hours
- **Stats: asset tracking** — `badal_logo.svg` and `badal_favicon.svg` are no longer counted as episode plays
- **Episode page colors** — episode page now loads theme colors and fonts from ThemeManager instead of hardcoded values
- **Font weight not saved** — fixed PHP numeric string key comparison (`===` between string and int) that prevented weight selection from persisting
- **Cover image URL** — header background image now uses `url()` helper for correct subdirectory support
- **Open Graph URLs** — fixed double subdirectory prefix in `og:image` and share URLs (`$baseUrl` + `url()` duplication)
- **Footer logo visibility** — uses CSS mask with `var(--text)` instead of `filter: invert()`, visible on both dark and light themes
- **Header padding** — left-aligned header now has horizontal padding on desktop and mobile
- **Telemetry UI text** — corrected "once per week" to "once per day" in account settings and dashboard comment

### Changed
- **Episode play button** — `.ep-play-btn` now matches `.featured-play` accent style (solid background, no border)
- **Episode card cursor** — `.episode-card-list` now shows pointer cursor on hover

---

## [0.12] — 2026-03-19

### Added
- **Export podcast** — ZIP export of all episodes, audio, covers, transcripts, theme and config from the podcast settings page
- **Delete podcast** — full podcast deletion with double-confirmation modal (episodes, audio, covers, transcripts, stats, config)
- **Setup success animation** — Badal logo drawn progressively (SVG stroke animation) on setup completion

### Fixed
- **RSS feed: malformed XML** — all URLs and attribute values are now XML-escaped via `htmlspecialchars()`, preventing broken feeds when `base_url` or slugs contain special characters (`&`, `<`, `>`, quotes)
- **RSS feed: broken audio URLs with subdirectories** — audio paths like `my-episode/audio.mp3` were fully encoded by `rawurlencode()`, turning `/` into `%2F`; now each path segment is encoded individually
- **RSS feed: double slashes in URLs** — `base_url` is now normalized (`rtrim`) so a trailing slash no longer produces `https://example.com//episodes/...`
- **RSS feed: unescaped optional fields** — `itunes:duration` and `itunes:episode` values are now XML-escaped
- **RSS feed: invalid `length="0"` in enclosure** — episodes whose audio file is missing on disk are now silently skipped instead of emitting an invalid `<enclosure>` tag
- **RSS feed: inconsistent XML indentation** — channel metadata and image blocks now use the same 4-space indentation as item blocks
- **Image upload** — `handleUpload()` in style settings now correctly receives the allowed extensions list
- **Footer links** — RSS and Admin links are now visible even when a custom footer text is set

### Changed
- License changed from MIT to **GPL v2 or later**
- Telemetry endpoint updated to `robotetdragon.com`; interval reduced from 7 days to 24 hours
- Sidebar: removed tooltip `data-tip` wrappers and `title` attribute on about link
- RSS builder: smaller score label font size for better fit

---

## [0.1] — 2026-03-16

### First public release

#### Core
- Flat-file PHP architecture — no database
- Episodes in Markdown + YAML frontmatter
- RSS 2.0 feed with iTunes namespace (Apple Podcasts compatible)
- XML sitemap generation
- Audio proxy with play count tracking

#### Admin interface
- Dashboard with KPIs (episodes, plays, storage)
- Episode management (create, edit, delete)
- Audio upload with auto duration detection via ffprobe
- Cover image upload per episode
- Markdown editor with preview (EasyMDE)
- Transcript support

#### Theme & appearance
- 14 Google Fonts with weight selection
- Custom color palette with 5 presets
- Dark/light mode
- List and grid episode styles
- Responsive admin and public pages

#### Statistics
- Play count per episode, per day
- Interactive Chart.js graphs
- CSV and PDF export
- Engagement ring per episode (estimated)

#### Distribution
- RSS validation score (Apple Podcasts checklist)
- One-click copy RSS URL
- Links to Apple Podcasts Connect, Spotify, Google Podcasts

#### Import
- Full podcast import from any RSS feed
- Downloads audio files and cover images
- Creates episode files with metadata
- Real-time progress bars

#### Security
- bcrypt password hashing (cost 12)
- CSRF tokens on all POST forms
- Rate-limiting (5 attempts / 15 min lockout)
- Session with UA fingerprint and expiry
- finfo MIME validation on uploads
- HTTP security headers (CSP, HSTS, X-Frame-Options)
- Audio proxy rate-limit (120 req/min per IP)
- Session regeneration on password change

#### Internationalization
- 4 languages: Français, English, Español, Português
- Language picker in setup, admin, and episode pages

#### Developer
- Update checker (checks GitHub Releases once/24h)
- Opt-in anonymous telemetry (weekly, no personal data)
- Version constant: `Version::CURRENT`
