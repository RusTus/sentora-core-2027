# Modern template

The panel's default template: the original Sentora layout in the colours of the Server Monitor module, in three
variations: **Light** (`css/light.css`), **Dark** (`css/dark.css`) and **Auto** (`css/auto.css`: Light or Dark,
following the computer's own light/dark setting).

New installs use Modern, Light. A reseller (or the administrator) sets the variation for their
clients in **Theme Manager**; anyone can choose their own in **My Account > Appearance**, which
Theme Manager never overrides. The
variation menu stays on the Theme Manager page, so Light and Dark can be switched
at any time. The login page follows the administrator (`zadmin`) account's choice.

## Files

| File | What it is |
| --- | --- |
| `css/_layout.css` | Layout (from the original Sentora_Default theme's `default.css`, which Modern replaced) |
| `css/_modern.css` | Every colour rule, written with `--ml-*` variables |
| `css/_vars-light.css`, `css/_vars-dark.css` | The two palettes (the `--ml-*` colours) |
| `css/light.css`, `css/dark.css`, `css/auto.css` | The variations: each imports the parts above (`auto.css` imports the dark palette only under `prefers-color-scheme: dark`) |
| `img/icons/<module>.svg` | One icon per module ([Lucide](https://lucide.dev), ISC license, see `img/icons/LICENSE.txt`) |
| `img/logos/sentora_logo_header_ink.png` | Header logo with dark text (Light) |
| `img/logos/sentora_logo_light.png` | Login logo with light text (Dark) |

A file in `css/` whose name starts with `_` is a part, not a variation: Theme
Manager does not list it.

## Module icons

The dashboard draws each module's `img/icons/<module>.svg` as a mask in its box's
colour (the boxes take blue, green, amber, purple, teal and red in turn). The
sidebar and top-bar menus draw it in the link's colour, and a module page shows it
in its header. A module with no icon here (an add-on, for example) shows its own
`assets/icon.png` on the same tile. To give an add-on an icon, add
`img/icons/<module folder name>.svg`.

Any other template can use the same mechanism by adding an `img/icons/` folder.

## Adding a variation

Add a palette `css/_vars-<name>.css` (copy `_vars-light.css`) and `css/<name>.css` that imports
`_layout.css`, `_modern.css` and it. Theme Manager and My Account list it by name.
