# Sentora-2027 — Project Hand-off (for Claude Code)

This guide hands off the Sentora-2027 project to a Claude Code session running on the
user's machine, where it has **direct git push access** to the repos (this cloud session
could not push — the org's GitHub App blocks it).

## What this project is

Sentora-2027 is a ground-up security rebuild of Sentora 2.0.2 for **Ubuntu 26.04 only**
(PHP 8.5, MySQL 8.4, Apache 2.4). Two GitHub repos under the **RusTus** account:

| Repo | Contents | Branch | Releases |
| --- | --- | --- | --- |
| `RusTus/sentora-core-2027` | The panel: core, modules, bundled apps | `master2027` | tag `current` |
| `RusTus/sentora-installers-2027` | Installer, service configs, SQL, add-on repo | `master2027` | tag `current` |

`current` is a **rolling release**: a new build replaces that release's assets (not a new tag).

## Git workflow (you can push here)

- Clone both repos, work on branch `master2027`, commit and **push to origin directly**.
- Use the `gh` CLI for releases (`gh release upload <repo> current <file> --clobber`).
- The user is Russell Skinner (`rskinner@hemsaw.com`).

## Repo layout

**core (`sentora-core-2027`)**
- `bin/` — CLI tools: `sentora-update`, `sentora-restore`, `sentora-panel-restore`,
  `sentora-post-install-check`, `sentora-smoke-test`, `zppy`, `setso`/`setso --set`.
- `modules/<name>/{code,hooks}` — panel modules (controllers run as the `sentora` web
  user; `hooks/OnDaemonRun.hook.php` run by the **root daemon**).
- `dryden/` — framework (`ctrl/options.class.php` has `GetSystemOption`/`SetSystemOption`).
- `cnf/` (db.php, security.php — server-specific, gitignored), `inc/`, `etc/`,
  `CHANGELOG.md`, `README.md`, `SECURITY.md`, `VERSION`, `.gitignore`.

**installers (`sentora-installers-2027`)**
- `preconf/bin/zsudo.c` — the setuid-root helper (compiled by the installer with `cc -O2`).
- `preconf/sentora-install/sql/sentora_core.sql` — the DB seed (x_settings rows etc.).
- `preconf/` — service configs (Apache, Postfix, Dovecot, ProFTPD, BIND, SOGo, fail2ban).
- `sentora-repo/` — the add-on "zppy" repository: `*.zpp`, `packages.txt`, `SHA256SUMS`.
- The bootstrap installer `sentora2027-ub26.04` is a **standalone release asset**, NOT
  tracked in the repo (its working source lived in the cloud session as `inst12.sh`).

## Release assets (what must be on each `current` release)

- **core**: `sentora-core-2027.zip`
- **installers**: `sentora2027-ub26.04`, `sentora-installers-2027.zip`,
  `sentora-php-8.1/8.2/8.3/8.4-ubuntu26.04-amd64.tar.gz`, `sentora-web-probe`,
  `sentora-tls-check.sh`, `sentora-smoke-test`, `testssl_sh-3_2_5.zip`

The installer and `sentora-update` verify every download against the SHA-256 GitHub
publishes for each asset. Keep a `SHA256SUMS.txt` with each build and `Get-FileHash`/
`sha256sum` before uploading (a corrupted upload bit us once).

## Current state (as of 2026-11-06)

Everything is committed and released. Both `current` releases verified complete and
byte-matched; the zppy repo is complete with matching checksums. Most recent changes:

- **`panel_ip_access`** setting (Sentora Config, default **on**): panel answers on the
  server IP as well as its name; unknown names get 404. `false` → IP gets 404 too. The
  gate lives in `modules/apache_admin/hooks/OnDaemonRun.hook.php` (`SentoraPanelAliases`);
  seeded in `sentora_core.sql`.
- **Installer default domain** → `sentora.<domain>` (prefix `sentora`), prompt example
  `(example: subdomain.yourdomain.org)`.
- **`sentora-tls-check.sh`** downloads testssl from the installers release (checksum) and
  installs `socat`.
- **DNS Admin "Set Permissions"** removed (it ran `zsudo chmod 0777` on the BIND log).
- Docs updated: `CHANGELOG.md`, the Administrator's Guide, and a "Changes and Fixes vs
  Sentora 2.0.2" summary doc.

## Design constraints — do NOT break these

- **Per-account isolation** is the core security property: each account runs as its own
  `web<id>` Linux user with its own PHP-FPM pool; the panel runs as the `sentora` user.
- **zsudo** is setuid-root with a **strict argv whitelist** and a scrubbed environment.
  The allowed service list is **hardcoded in root-owned `zsudo.c` by design** — do NOT
  make it panel/DB-editable. Reason: the panel's MySQL login is `root`, so a DB-driven
  allowlist would move the root boundary into panel-writable storage.
- **Account restore** must stay scoped: per-database temp MySQL user, `DEFINER` stripped,
  `--defaults-file`, web files restored as the account user.
- All known servers are **test VMs**; `hembackup1` is defunct and already removed.

## Testing

- On a server (as root): `sentora-post-install-check`, `sentora-smoke-test`,
  `bash sentora-tls-check.sh <panel-name>`, and `sentora-field-test` (combined report).
- Last field test: post-install **80 OK / 0 FAIL**, smoke **26/26**, web-probe
  **47 OK / 0 FAIL**. The TLS "CRITICAL"s are all the self-signed cert and clear once a
  real cert is issued.

## Open items

1. Issue a **Let's Encrypt** cert via Sencrypt once the panel name is on public DNS
   (this flips the TLS grades from T to A).
2. (Optional, before production with untrusted tenants) an independent security review;
   a threat-model doc (`THREAT-MODEL.md`) was drafted for this.

## How to cut a release

1. Commit source to `master2027` on the relevant repo(s).
2. Build `sentora-core-2027.zip` (core tree) / `sentora-installers-2027.zip` (installers
   `preconf` etc.), keeping the `<name>/` top-level folder prefix.
3. `sha256sum` all assets into `SHA256SUMS.txt`.
4. `gh release upload RusTus/<repo> current <assets> --clobber`.
5. Verify on a fresh install with `sentora-field-test`.
