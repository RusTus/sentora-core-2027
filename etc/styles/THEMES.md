# Sentora themes

Each folder here with a `master.ztml` is a theme (template) that Theme Manager offers.
**Modern** is the default; **Kerf** is optional. A folder whose name starts with `_` is not a
theme: `_shared/` holds the files every theme uses (Bootstrap, jQuery and the other scripts,
the fontello and Glyphicons fonts, favicons, OS icons, small images), so a theme folder only
needs its own templates, CSS and images. Link them as `etc/styles/_shared/...` (in a
stylesheet: `../../_shared/...`). In PHP, `ui_template::AssetPath('img/misc/unlimited.png')`
gives the theme's own copy of a file when it has one, else the shared one.

## What a theme folder holds

| Path | Needed | What it is |
| --- | --- | --- |
| `master.ztml` | yes | The page around every module (top bar, sidebar, content) |
| `login.ztml` | yes | The login page (shown in the administrator's theme and variation; `$loginCss` holds the variation name) |
| `css/<name>.css` | yes, at least one | One file per **variation**. Theme Manager offers them as a choice when there is more than one, in this order: `default`, `light`, `dark`, the rest by name, `auto` last. The label is the file name ("high_contrast" shows as "High contrast"). |
| `css/_<name>.css` | no | A part the variations `@import`; never offered as a variation |
| `img/icons/<module>.svg` | no | The theme's own module icons (dashboard, menus, module page header). A module without one shows its `assets/icon.png`. |
| `img/modules/<module>/assets/icon.png` | no | Older way to replace one module's PNG icon |

Which variation a person sees: their own choice in **My Account > Appearance** (kept in
`x_user_styles`), else the one their reseller set in **Theme Manager**, else the theme's
first. A choice the current theme does not have is skipped. Name a variation `auto` when it
follows the computer's light/dark setting (it is listed last).

## Colours modules use: `--sn-*`

Modules colour their own boxes, borders and status text with these variables, each
with a fallback (`border: 1px solid var(--sn-line, #ddd)`), so they follow the
theme (including a dark variation) and still look right in a theme without them.
**Every theme should define all of them** on `:root`:

| Variable | Used for | Modern Light |
| --- | --- | --- |
| `--sn-card` | Box and table backgrounds | `#ffffff` |
| `--sn-ink` | Text | `#1f2933` |
| `--sn-muted` | Secondary text, labels | `#6b7785` |
| `--sn-line` | Borders, dividers | `#e6e9ee` |
| `--sn-line-strong` | Input borders, stronger dividers | `#cfd6de` |
| `--sn-soft` | Quiet panels (forms, help boxes), table heads | `#f4f6f9` |
| `--sn-hover` / `--sn-hover-strong` | Hovered and selected rows, tabs | `#f4f6f9` / `#e6e9ee` |
| `--sn-accent` / `--sn-accent-soft` | Selection, links / its tint | `#447fb3` / tint |
| `--sn-ok`, `--sn-warn`, `--sn-bad`, `--sn-info` | Status text and icons (readable on `--sn-card`) | `#1e8a4c`, `#a86b00`, `#c62f2b`, `#2f6f9f` |
| `--sn-ok-soft`, `--sn-warn-soft`, `--sn-bad-soft`, `--sn-info-soft` | Status backgrounds | tints |
| `--sn-ok-line`, `--sn-warn-line`, `--sn-bad-line`, `--sn-info-line` | Status borders | tints |

In a module, write colours only through these (with a fallback), and never set a
light background without the matching text colour. Bootstrap classes (`panel`,
`table`, `btn-*`, `alert-*`, `form-control`) are styled by the theme already.

## Caching

The panel sends theme and module files (`/etc/styles/`, `/modules/*/assets/`) with
`Cache-Control: no-cache`: browsers keep them but check for a newer copy on each
use, so changed CSS reaches everyone after an update, `@import`ed parts included.
