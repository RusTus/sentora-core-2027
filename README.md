# Sentora-2027

An open-source web hosting control panel for **Ubuntu 26.04**, rebuilt from Sentora 2.0.2 for PHP 8.5, MySQL 8.4 and Apache 2.4.

![Release](https://img.shields.io/badge/release-Sentora--2027-2b6cb0)
![Ubuntu](https://img.shields.io/badge/Ubuntu-26.04-E95420?logo=ubuntu&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/license-GPLv3-blue)

Sentora-2027 runs a web hosting server from the browser: websites, domains and DNS, mail, FTP, databases, backups and statistics, for any number of client accounts. Each account runs as its own Linux user, so one client can't reach another client's files, databases or mail.

> [!IMPORTANT]
> Sentora-2027 needs a **fresh Ubuntu 26.04 server**. It can't upgrade an existing Sentora or ZPanel server, and the installer stops if it finds one.

## Repositories

| Repository | Branch | Contents | Release file |
| --- | --- | --- | --- |
| [RusTus/sentora-core-2027](https://github.com/RusTus/sentora-core-2027) | `master2027` | The panel (this repository) | `sentora-core-2027.zip` |
| [RusTus/sentora-installers-2027](https://github.com/RusTus/sentora-installers-2027) | `master2027` | Service configuration, SQL and build sources | `sentora-installers-2027.zip` |

## Contents

- [Repositories](#repositories)
- [What's included](#whats-included)
- [Requirements](#requirements)
- [Installing](#installing)
- [After installing](#after-installing)
- [Making a release](#making-a-release)
- [Updating](#updating)
- [Backups and restore](#backups-and-restore)
- [Add-on modules](#add-on-modules)
- [Security](#security)
- [Repository layout](#repository-layout)
- [Documentation](#documentation)
- [License and credits](#license-and-credits)

## What's included

| Area | Software |
| --- | --- |
| Web | Apache 2.4 (event engine, HTTP/2), PHP 8.5 through PHP-FPM; PHP 8.2, 8.3 and 8.4 per domain |
| Databases | MySQL 8.4, phpMyAdmin 5.2 |
| Mail | Postfix 3.10, Dovecot 2.4, Roundcube 1.7, SOGo 5.12 (calendars, contacts, ActiveSync), SpamAssassin and ClamAV |
| DNS | BIND 9.20 |
| FTP | ProFTPD 1.3.9 with FTPS required |
| Statistics | Matomo 5, GoAccess 1.12; Webalizer and Visitors as add-on packages |
| Security | Two-factor login, fail2ban, login lockout, a self-signed certificate and Let's Encrypt through Sencrypt |

Modules include the panel's own File Manager, Cron Manager (with a file picker), Protected Directories, Server Monitor, System Log Viewer, Apache Status, PHP Versions, Repo Browser, and off-server backups with restore.

## Requirements

- A clean install of Ubuntu 26.04 LTS (amd64). No other release or distribution is supported.
- Root access, and access to github.com from the server.
- A correct clock. The installer checks it and stops if it is more than 2 minutes off.
- A host name for the panel (for example `panel.example.com`) and the server's IP address.

## Installing

The installer downloads everything it needs from the GitHub releases of the two repositories:

| Release | Files |
| --- | --- |
| [sentora-core-2027](https://github.com/RusTus/sentora-core-2027/releases) | `sentora-core-2027.zip` |
| [sentora-installers-2027](https://github.com/RusTus/sentora-installers-2027/releases) | `sentora2027-ub26.04` (the installer), `sentora-installers-2027.zip`, `sentora-php-8.2-ubuntu26.04-amd64.tar.gz` to `8.4` (optional extra PHP versions) |

Each download is checked against the SHA-256 GitHub lists for it. On the new server, as root:

```bash
wget -O sentora2027-ub26.04 https://github.com/RusTus/sentora-installers-2027/releases/download/current/sentora2027-ub26.04
bash sentora2027-ub26.04
```

The installer asks for the time zone, the panel's host name and the IP address. To run it without questions:

```bash
bash sentora2027-ub26.04 -t America/Chicago -d panel.example.com -i public
```

| Option | Meaning |
| --- | --- |
| `-t <time zone>` | Time zone, for example `Europe/London` |
| `-d <host name>` | The panel's host name (needs `-i`) |
| `-i <address>` | The server's IP address, or `local` / `public` to use the detected one (needs `-d`) |

Settings at the top of the installer choose where it downloads from:

| Setting | Default | Meaning |
| --- | --- | --- |
| `SENTORA_RELEASE_TAG` | `current` | The release to install from both repositories; `latest` takes the newest, pre-releases included |
| `SENTORA_CORE_REPO`, `SENTORA_INSTALLERS_REPO` | `RusTus/sentora-core-2027`, `RusTus/sentora-installers-2027` | The repositories |
| `SENTORA_DOWNLOAD_URL` | empty | A folder on your own web server holding all the files instead of GitHub |

## After installing

Every generated password, including the panel's `zadmin` password, is shown at the end of the install and saved in `/root/passwords.txt`.

These checks are installed with the panel:

```bash
sentora-post-install-check   # services, ports, permissions and settings
sentora-smoke-test           # creates a test client and uses the server like a real one (about 2 minutes)
```

From another computer, these check what visitors can reach:

```bash
./sentora-web-probe panel.example.com   # headers, cookies and files that must not be served
./sentora-tls-check.sh panel.example.com
```

> [!TIP]
> Once the panel's host name is in public DNS, issue a Let's Encrypt certificate with **Sencrypt**. Mail and FTP switch to it within 5 minutes.

To move the panel to another address later (its DNS A record pointing to this server first), as root:

```bash
sentora-change-domain panel.new-example.com
```

It changes the name everywhere the installer put it (the panel and its Apache site, the host name, Postfix, Dovecot, SOGo, Matomo) and makes a new self-signed certificate for it; request a Let's Encrypt one in Sencrypt after. The changed files are kept as `<file>.before-<new name>`. **Admin > Sentora Config** shows the current address.

## Making a release

1. Build the zips from a clone of each repository (each zip holds one top folder named like the zip):

   ```bash
   git archive --prefix=sentora-core-2027/ -o sentora-core-2027.zip master2027
   git archive --prefix=sentora-installers-2027/ -o sentora-installers-2027.zip master2027
   ```

2. Both repositories have one release with the tag **`current`**. In each, open that release, click **Edit**, delete the old files and attach the new ones:
   - core: `sentora-core-2027.zip`
   - installers: `sentora2027-ub26.04`, `sentora-installers-2027.zip` and the PHP bundles
3. Save the release (leave it published, not as a draft).

New installs and `sentora-update` take the files from the `current` release, so they get the new version as soon as it's saved. GitHub lists each file's SHA-256 on the release page; the installer and `sentora-update` check every download against it.

## Updating

```bash
sentora-update --check      # is there a newer release?
sentora-update              # back up the panel, then update it (asks first)
sentora-update --rollback   # list the backups and restore one
```

`sentora-update` installs the core zip from the `current` release of [sentora-core-2027](https://github.com/RusTus/sentora-core-2027/releases), and knows a new version by its changed SHA-256. To use another release, set `TAG="<tag>"` in `/etc/sentora/update.conf` (`TAG="latest"` takes the newest release, pre-releases included). It updates the panel only and keeps this server's own settings; changes to the service configuration (Postfix, Dovecot, Apache and so on) are described in the [change log](CHANGELOG.md).

It shows each step with a progress bar, like the installer, and keeps the details in `/var/sentora/logs/sentora-update.log`. Once an hour the panel checks for a newer release (GitHub's API only, nothing is downloaded) and shows administrators an "A panel update is available" notice. Closing the notice hides it until the next login, and it goes away once the panel is updated.

The same can be done from the panel: **Admin > Server Admin > Panel Update** shows the installed and the published release, updates with **Update now** (after your panel password) and shows each step as it runs, and lists the backups with **Go back**. The panel asks root through `zsudo`, which runs `sentora-update` exactly as on the console. Every request is logged in `/var/lib/sentora/panel-update.log`. If the panel does not answer after an update, go back from the console, as root:

```bash
sentora-update --rollback
```

It lists the backups, newest first; run it again with the newest one (`sentora-update --rollback /var/sentora/backups/panel-before-update-<date>.tar.gz`).

## Backups and restore

- **Client backups:** each account can back up its own websites, databases and mail from the panel.
- **Full panel backups:** **Admin > Backup Config** copies every account, database, mailbox and the panel's configuration to another machine each night, as a structured snapshot.
- **Restore:** the same page, or `sentora-panel-restore`, restores one account or the whole panel, also on a new server. What is there before is always saved first.

## Add-on modules

Optional modules are installed from **Admin > Repo Browser**, which lists the Sentora-2027 add-on repository by default:

| Module | What it does |
| --- | --- |
| PHP-Adminer | Adminer for each account's own MySQL databases |
| Webalizer Stats | Webalizer traffic reports per domain |
| Visitor Stats | Visitors reports per domain |

The modules live in the [`sentora-repo` folder](https://github.com/RusTus/sentora-installers-2027/tree/master2027/sentora-repo) of the installers repository. Every download is checked against its `SHA256SUMS`.

## Security

- The panel runs as its own user (`sentora`) and every hosting account as its own Linux user, each with its own PHP-FPM pool.
- Every action checks that the item belongs to the logged-in account, and every form carries a CSRF token.
- Logins lock after repeated wrong passwords, and two-factor login is available.
- Every module was tested for cross-account access with a second client trying to reach the first client's data.

The full list of fixes is in the [change log](CHANGELOG.md). To report a vulnerability, see the [security policy](SECURITY.md).

## Repository layout

| Path | Contents |
| --- | --- |
| `bin/` | The daemon, `sentora-update`, backup, restore and check tools |
| `cnf/` | Templates for the database settings and encryption key (the installer fills them in on the server) |
| `dryden/` | The panel's framework classes |
| `etc/apps/` | Bundled web apps: phpMyAdmin, Roundcube, Matomo |
| `etc/styles/` | Themes |
| `inc/` | Start-up code |
| `modules/` | Panel modules, one folder each |

## Documentation

- [Change log](CHANGELOG.md): everything changed from Sentora 2.0.2, with the security fixes
- [Security policy](SECURITY.md)
- [Contributing](.github/CONTRIBUTING.md)
- [Issues](https://github.com/RusTus/sentora-core-2027/issues)

## License and credits

Sentora-2027 is licensed under the [GNU General Public License v3](LICENSE.md).

It is based on [Sentora](https://github.com/sentora/sentora-core) by the Sentora team, itself a fork of ZPanelCP. Bundled applications keep their own licenses, listed in their folders.
