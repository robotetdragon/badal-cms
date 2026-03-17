# Badal — User Guide

## Table of contents

1. [First setup](#1-first-setup)
2. [Your first episode](#2-your-first-episode)
3. [Submitting to Apple Podcasts](#3-submitting-to-apple-podcasts)
4. [Customizing the theme](#4-customizing-the-theme)
5. [Importing an existing podcast](#5-importing-an-existing-podcast)
6. [Statistics explained](#6-statistics-explained)
7. [FAQ](#7-faq)

---

## 1. First setup

Open `setup.php` on your server:

1. **Choose a language** — Français, English, Español, Português
2. **Set a username** — letters and numbers, 3–32 characters
3. **Set a password** — minimum 8 characters

Badal detects the base URL automatically. Delete `setup.php` after installation.

**First steps after install:**
1. Go to **My Podcast** — add title, description, author, email
2. Upload a **3000×3000px cover** (required by Apple Podcasts)
3. Create your first episode

---

## 2. Your first episode

Go to **Episodes → New Episode**.

| Field | Description |
|-------|-------------|
| Title | Shown everywhere |
| Slug | URL identifier, auto-generated |
| Date | Publication date |
| Duration | Auto-filled on audio upload |
| Description | Short text for RSS (1–2 sentences) |
| Show notes | Full Markdown for the episode page |
| Audio | MP3, OGG, M4A, AAC, WAV, FLAC, OPUS |
| Cover | Optional, falls back to podcast cover |

---

## 3. Submitting to Apple Podcasts

1. Go to **Distribution RSS**
2. Validate your score (aim for 100%)
3. Copy your RSS URL
4. Submit at [podcasters.apple.com](https://podcasters.apple.com)

**Requirements:** cover 3000×3000px · non-empty description · at least one episode with audio

---

## 4. Customizing the theme

Go to **Appearance**. Changes auto-save.

- **Colors** — 5 presets or custom hex values
- **Typography** — 14 Google Fonts, independent weight per role
- **Texts** — tagline, CTA, footer, social links
- **Media** — logo and hero cover image
- **Layout** — width, alignment, list vs grid

---

## 5. Importing an existing podcast

Go to **Episodes → Import a podcast** (visible only when no episodes exist).

Provide an RSS URL or upload an XML file. Badal will download all audio, covers, and metadata automatically.

---

## 6. Statistics explained

Plays are counted server-side — works with all podcast apps, no JavaScript needed.

**Counted:** initial audio request (no Range, or Range: bytes=0)
**Not counted:** seeks, resumes, partial re-downloads

---

## 7. FAQ

**Q: Does Badal work on shared hosting?**
Yes. Tested on IONOS, OVH. Requires PHP 7.4+ and Apache mod_rewrite.

**Q: How do I change the base URL?**
Edit config/config.php directly. Do not change it via admin.

**Q: How do I reset my password?**

    php -r "echo password_hash('newpass', PASSWORD_BCRYPT, ['cost'=>12]);"

Replace admin_password_hash in config/config.php.

**Q: Can I have multiple users?**
Not in v0.1. Single-user only.

**Q: How do I back up my podcast?**
Zip content/, audio/, and config/. That is everything.
