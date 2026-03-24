# Contributing to Badal

Thank you for your interest in contributing! 🎙️

---

## Ways to contribute

- **Report bugs** — open an issue with steps to reproduce
- **Suggest features** — open an issue tagged `enhancement`
- **Fix bugs** — fork, fix, open a PR
- **Translate** — add a new language file in `lang/`
- **Documentation** — improve README, HELP, or inline comments

---

## Development setup

### Option A — Docker (recommended)

```bash
git clone https://github.com/badal-cms/badal.git
cd badal/docker
docker compose up -d
```

Open http://localhost:8080 — a demo podcast with 3 episodes is ready.
Admin: http://localhost:8080/admin/ — login: `admin` / `admin`

Your local PHP files are live-mounted: edit code, refresh the browser.

To reset the demo data:
```bash
docker compose down -v
docker compose up -d
```

### Option B — Local PHP server

1. Clone the repo
   ```bash
   git clone https://github.com/badal-cms/badal.git
   cd badal
   ```

2. Upload to a PHP server (local: MAMP, Laragon, or `php -S localhost:8000`)

3. Open `setup.php` in your browser to initialize

---

## Code conventions

- **PHP 7.4+ compatible** — no named arguments, no enums, no fibers
- **No dependencies** — no Composer, no npm
- **Flat files only** — no database queries
- **Security first** — validate all user input, escape all output
- All user-facing strings go through `__(key)` and must be added to all 4 lang files
- New upload handlers must use `finfo_file()` for MIME validation

---

## Adding a language

1. Copy `lang/en.php` to `lang/xx.php`
2. Translate all values (keep the keys in English)
3. Add `'xx'` to `Lang::SUPPORTED_LANGS` in `core/Lang.php`
4. Add the label and flag to `Lang::LABELS`
5. Add the language to `setup.php` `$T` and `$LANG_DATA` arrays

---

## Pull request checklist

- [ ] No new external dependencies
- [ ] Tested on PHP 7.4 and PHP 8.x
- [ ] All user strings go through `__()`
- [ ] New uploads validated with `finfo`
- [ ] No SQL, no database
- [ ] CHANGELOG updated

---

## Reporting security issues

**Do not open a public issue for security vulnerabilities.**  
Email: security@badal-cms.com (or open a private GitHub advisory)
