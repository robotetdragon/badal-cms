# Changelog

All notable changes to Badal are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
