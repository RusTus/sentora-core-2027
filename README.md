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
- [Building the release zips](#building-the-release-zips)
- [Updating](#updating)
- [Backups and restore](#backups-and-restore)
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
- Root access.
- A correct clock. The installer checks it against the download server and stops if it is more than 2 minutes off.
- A host name for the panel (for example `panel.example.com`) and the server's IP address.
- Access to the download server that holds the release files. It is set by `SENTORA_DOWNLOAD_URL` at the top of `sentora2027-ub26.04`.

## Installing

Put these files on the download server, in the folder `SENTORA_DOWNLOAD_URL` points to:

| File | What it is |
| --- | --- |
| `sentora2027-ub26.04` | The installer |
| `sentora-core-2027.zip` | The panel (this repository) |
| `sentora-installers-2027.zip` | Service configuration, SQL and build sources |
| `sentora-php-8.2-ubuntu26.04-amd64.tar.gz` to `8.4` | Optional extra PHP versions (a missing file means that version is not installed) |
| `SHA256SUMS` | Checksums used by `sentora-update` |

Then, on the new server, as root:

```bash
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

## Building the release zips

The installer and `sentora-update` expect each zip to hold one top folder named like the zip. From a clone of each repository:

```bash
git archive --prefix=sentora-core-2027/ -o sentora-core-2027.zip master2027
git archive --prefix=sentora-installers-2027/ -o sentora-installers-2027.zip master2027
sha256sum sentora2027-ub26.04 sentora-*.zip sentora-php-*.tar.gz > SHA256SUMS
```

Copy them, with the installer, to the download server.

## Updating

```bash
sentora-update --check      # is there a newer version?
sentora-update              # back up the panel, then update it (asks first)
sentora-update --rollback   # list the backups and restore one
```

`sentora-update` updates the panel only, and keeps this server's own settings. Changes to the service configuration (Postfix, Dovecot, Apache and so on) are described in the [change log](CHANGELOG.md).

## Backups and restore

- **Client backups:** each account can back up its own websites, databases and mail from the panel.
- **Full panel backups:** **Admin > Backup Config** copies every account, database, mailbox and the panel's configuration to another machine each night, as a structured snapshot.
- **Restore:** the same page, or `sentora-panel-restore`, restores one account or the whole panel, also on a new server. What is there before is always saved first.

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
