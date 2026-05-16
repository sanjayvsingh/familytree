# Family Tree Viewer

A browser-based family tree viewer that reads standard GEDCOM files and renders an interactive, navigable tree — no database or build step required.

## Features

- **Interactive tree** — centered on any individual, showing grandparents, parents, siblings, spouses, and children at a glance
- **Auto-selects a default person** — opens the most-connected individual automatically when a file is loaded
- **Click to navigate** — click any person card to re-center the tree on them
- **People panel** — alphabetically sorted sidebar (by last name, then given name) with a live filter box; unknown surnames sort to the bottom; click any name to navigate
- **Detail panel** — vital records (birth, death, burial, christening), occupation, religion, residence, notes, and clickable relationship links
- **Upcoming dates** — scrollable panel showing birthdays and wedding anniversaries in the next 90 days; filters out people born more than 90 years ago who have no recorded death date
- **Pan & zoom** — drag to pan, scroll wheel or pinch to zoom, touch-friendly on mobile
- **Magic-link authentication** — passwordless email sign-in with a 3-digit code or click-to-verify link; 30-day sessions; rate-limited per IP
- **Privacy-safe** — GEDCOM files are gitignored and never leave your server

## Requirements

- PHP 7.4+ with `curl` and `iconv` extensions (both bundled with PHP by default)
- A web server (Apache with `mod_rewrite`, or `php -S localhost:8000` for local use)

## Setup

1. Clone the repo:
   ```bash
   git clone https://github.com/sanjayvsingh/familytree.git
   cd familytree
   ```

2. Copy and fill in the config:
   ```bash
   cp config.example.php config.php
   ```
   Edit `config.php` with your SMTP2GO API key, sender address, and (optionally) an email allowlist.

3. Place your `.ged` files in the `gedcom/` directory:
   ```bash
   mkdir gedcom
   cp /path/to/your/family.ged gedcom/
   ```

4. Start a local server:
   ```bash
   php -S localhost:8000
   ```

5. Open `http://localhost:8000` in your browser. You'll be prompted to sign in with your email before the tree loads.

### Apache (production)

The `.htaccess` in the project root enables `mod_rewrite`, sets security headers, enables gzip compression, and blocks direct access to `auth/`, `cache/`, and `gedcom/`. Make sure `AllowOverride` is enabled for the directory.

**Shared hosting note:** On first run PHP creates the `auth/` directory. If it lands at mode 0700, Apache cannot traverse it and will return 403. Fix by setting `auth/` and its subdirectories (`tokens/`, `sessions/`, `rate/`, `logs/`) to mode 755 in your file manager.

## GEDCOM Support

Parses GEDCOM 5.5.1 using a streaming line-by-line reader (handles large files without memory issues). Supported records and tags:

| Record | Tags parsed |
|--------|------------|
| `INDI` | `NAME` (with `NPFX GIVN NICK SPFX SURN NSFX`), `SEX`, `BIRT DEAT BURI CHR BAPM CONF ADOP GRAD RETI EMIG IMMI` (with `DATE PLAC AGE`), `OCCU RELI RESI`, `NOTE`, `FAMS FAMC` |
| `FAM`  | `HUSB WIFE CHIL`, `MARR DIV` (with `DATE PLAC`), `NOTE` |
| `NOTE` | Inline and `@xref@` pointer notes, with `CONT`/`CONC` continuation |

**Encoding:** UTF-8 (with BOM stripping), ANSI/Windows-1252, and ISO-8859-1 files are all handled automatically via the `CHAR` header tag.

**Name parsing:** Given names and surnames are extracted from the standard `Name /Surname/` slash notation when `GIVN`/`SURN` sub-tags are absent.

## Project Structure

```
familytree/
├── index.php           # Entry point — auth guard, file picker, HTML shell
├── api.php             # JSON API — session-gated, serves parsed GEDCOM data
├── auth.php            # Auth endpoint — magic link request, code verify, logout
├── auth_lib.php        # Auth library — tokens, sessions, rate limiting, email
├── login.php           # Login UI — email + 3-digit code flow
├── gedcom_parser.php   # Streaming GEDCOM 5.5.1 parser with two-tier caching
├── app.js              # Tree layout, rendering, navigation, panels, upcoming dates
├── style.css           # All styles (warm earth-tone theme, responsive)
├── config.example.php  # Config template — copy to config.php and fill in
├── .htaccess           # Apache rules — security headers, gzip, directory blocking
└── gedcom/             # Your .ged files go here (gitignored)
```

## Privacy & Security

- `gedcom/`, `auth/`, `cache/`, and `config.php` are all gitignored
- Token, session, and log files are written at mode 0600
- The `auth/` directory has a deny-all `.htaccess` blocking direct HTTP access
- All API endpoints require a valid session (Bearer token or `ft_session` cookie)
- Rate limiting: 5 auth attempts per IP per 10-minute window (applies to both magic-link requests and code verification)
