# Family Tree Viewer

A browser-based family tree viewer that reads standard GEDCOM files and renders an interactive, navigable tree — no database or build step required.

## Features

- **Interactive tree** — centered on any individual, showing grandparents, parents, siblings, spouses, and children at a glance
- **Click to navigate** — click any person card to re-center the tree on them, with a back button to retrace your steps
- **Detail panel** — vital records (birth, death, burial, christening), occupation, religion, residence, notes, and clickable relationship links
- **Live search** — type 3+ characters to find anyone in the tree by name
- **Upcoming dates** — scrollable panel showing birthdays and wedding anniversaries in the next 90 days
- **Pan & zoom** — drag to pan, scroll wheel or pinch to zoom, touch-friendly on mobile
- **Privacy-safe** — GEDCOM files are gitignored and never leave your server

## Requirements

- PHP 8.0+ (only the standard `file()` function — no extensions needed)
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

Parses GEDCOM 5.5.1. Supported records and tags:

| Record | Tags parsed |
|--------|------------|
| `INDI` | `NAME` (with `NPFX GIVN NICK SPFX SURN NSFX`), `SEX`, `BIRT DEAT BURI CHR BAPM CONF ADOP GRAD RETI EMIG IMMI` (with `DATE PLAC AGE`), `OCCU RELI RESI`, `NOTE`, `FAMS FAMC` |
| `FAM`  | `HUSB WIFE CHIL`, `MARR DIV` (with `DATE PLAC`), `NOTE` |
| `NOTE` | Inline and `@xref@` pointer notes, with `CONT`/`CONC` continuation |

Multi-byte characters (UTF-8 BOM) are handled automatically.

## Project Structure

```
familytree/
├── index.php           # Entry point — file picker, data injection, HTML shell
├── gedcom_parser.php   # GEDCOM 5.5.1 parser
├── js/
│   └── app.js          # Tree layout, rendering, navigation, search, upcoming dates
├── css/
│   └── style.css       # All styles (warm earth-tone theme, responsive)
└── gedcom/             # Your .ged files go here (gitignored)
```

## Privacy

The `gedcom/` directory and all `*.ged` files are excluded from git via `.gitignore` to prevent personal family data from being committed accidentally.
