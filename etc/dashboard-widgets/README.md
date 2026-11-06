# Dashboard widgets

Widgets uploaded in **Admin > Widget Admin** are installed here by the Sentora daemon,
one folder per widget (`<id>/`). Do not edit these folders by hand: upload a new version
of the package instead.

## The package

A `.zip` file containing exactly these files, at the top level (no folders):

| File | Required | What it is |
| --- | --- | --- |
| `widget.xml` | yes | the widget's description (below) |
| `widget.php` | yes | the class that draws the widget |
| `widget.css` | no | styles for the widget |

At most 512 KB in all.

### Looking like the panel (Light, Dark, Auto)

The widget is drawn inside a card on the dashboard, which already has the panel's look.
Use the dashboard's classes where they fit, and only the theme's colour variables in
`widget.css` (each with a fallback), so the widget also reads well in the Dark theme:

- `dw-usage` (a two-column table of names and values), `dw-list`, `dw-bar` with a
  `<span style="width: 40%">` inside (`is-mid`, `is-high` for amber and red);
- `label label-success | label-warning | label-danger | label-info` for small status pills,
  `text-muted` for secondary text, `btn btn-default btn-sm` for links to modules;
- colours: `var(--sn-ink)`, `--sn-muted`, `--sn-line`, `--sn-card`, `--sn-soft`, `--sn-accent`,
  and `--sn-ok`, `--sn-warn`, `--sn-bad`, `--sn-info` with their `-soft` and `-line` tints
  (the full list is in etc/styles/THEMES.md). Never a fixed light background or dark text.

### widget.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<widget>
    <id>quick_links</id>                <!-- a-z, 0-9 and _, 3 to 32 characters, starts with a letter -->
    <name>Quick links</name>
    <description>Webmail, SOGo, phpMyAdmin and the File Manager in one click.</description>
    <version>1.0</version>
    <author>Your name</author>
    <default>false</default>            <!-- on new dashboards: true or false -->
    <groups>administrators resellers users</groups>  <!-- who may use it at first -->
</widget>
```

Widget Admin can change the defaults and the groups afterwards.

### widget.php

```php
<?php
class dashboard_widget_quick_links          // dashboard_widget_ + the id
{
    /** the widget's HTML for this account ($user: ctrl_users::GetUserDetail()) */
    static function Render($user)
    {
        return '<p>' . htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') . '</p>';
    }
}
```

Rules for widget code:

- **Escape everything you print** with `htmlspecialchars()`.
- **Show only the logged-in account's own data**: `$user['userid']`, `$user['username']`.
  Query the database with prepared statements (`global $zdbh;`).
- Use `dashboard_widgets::CanOpen($user, '<module folder>')` before linking to a module.
- Do not change anything: a widget only shows information.
- Keep it fast: it runs every time the dashboard opens.

The code runs inside the panel, like a module, which is why only administrators can
upload widgets. A widget that fails shows "This widget could not be shown" instead of
breaking the dashboard; the error is in the panel's PHP log.
