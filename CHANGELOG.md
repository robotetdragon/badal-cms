# Changelog

All notable changes to Badal are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
