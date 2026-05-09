# Family Tree Viewer

A browser-based family tree viewer that reads standard GEDCOM files and renders an interactive, navigable tree — no database or build step required.

## Features

- **Interactive tree** — centered on any individual, showing grandparents, parents, siblings, spouses, and children at a glance
- **Auto-selects a default person** — opens the most-connected individual automatically when a file is loaded
- **Click to navigate** — click any person card to re-center the tree on them, with a back button to retrace your steps
- **People panel** — alphabetically sorted sidebar (by last name, then given name) with a live filter box; unknown surnames sort to the bottom; click any name to navigate
- **Detail panel** — vital records (birth, death, burial, christening), occupation, religion, residence, notes, and clickable relationship links
- **Upcoming dates** — scrollable panel showing birthdays and wedding anniversaries in the next 90 days; filters out people born more than 90 years ago who have no recorded death date
- **Pan & zoom** — drag to pan, scroll wheel or pinch to zoom, touch-friendly on mobile
- **Privacy-safe** — GEDCOM files are gitignored and never leave your server

## Requirements

- PHP 8.0+ with the `iconv` extension (bundled with PHP by default)
- A web server (Apache, Nginx, or `php -S localhost:8000`)

## Setup

1. Clone the repo:
   ```bash
   git clone https://github.com/sanjayvsingh/familytree.git
   cd familytree
   ```

2. Place your `.ged` files in the `gedcom/` directory (create it if it doesn't exist):
   ```bash
   mkdir gedcom
   cp /path/to/your/family.ged gedcom/
   ```

3. Start a local server:
   ```bash
   php -S localhost:8000
   ```

4. Open `http://localhost:8000` in your browser, select your GEDCOM file from the dropdown, and start exploring.

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
├── index.php           # Entry point — file picker, data injection, HTML shell
├── gedcom_parser.php   # Streaming GEDCOM 5.5.1 parser with encoding detection
├── style.css           # All styles (warm earth-tone theme, responsive)
├── app.js              # Tree layout, rendering, navigation, people panel, upcoming dates
└── gedcom/             # Your .ged files go here (gitignored)
```

## Privacy

The `gedcom/` directory and all `*.ged` files are excluded from git via `.gitignore` to prevent personal family data from being committed accidentally.
