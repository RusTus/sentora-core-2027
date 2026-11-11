# Sentora-2027

An open-source web hosting control panel for **Ubuntu 26.04**, **Debian 13**, **AlmaLinux 10**, **Rocky Linux 10**, **Red Hat Enterprise Linux 10** and **Fedora 45**, for **Windows 11 / Windows Server 2025** through WSL, and as a **Docker image**. Rebuilt from Sentora 2.0.2 for PHP 8.5, MySQL 8.4 and Apache 2.4 (on Debian, the RHEL family and Fedora: their own PHP and MariaDB), with SELinux or AppArmor on, on every Linux system.

**Website:** [sentorareloaded2027.github.io](https://sentorareloaded2027.github.io): screenshots, the install command for every system, and the Windows setup.

[![Website](https://img.shields.io/badge/website-sentorareloaded2027.github.io-1f7fae)](https://sentorareloaded2027.github.io)

![Release](https://img.shields.io/badge/release-Sentora--2027-2b6cb0)
![Ubuntu](https://img.shields.io/badge/Ubuntu-26.04-E95420?logo=ubuntu&logoColor=white)
![Debian](https://img.shields.io/badge/Debian-13-A81D33?logo=debian&logoColor=white)
![AlmaLinux](https://img.shields.io/badge/AlmaLinux-10-0F4266?logo=almalinux&logoColor=white)
![Rocky Linux](https://img.shields.io/badge/Rocky_Linux-10-10B981?logo=rockylinux&logoColor=white)
![RHEL](https://img.shields.io/badge/RHEL-10-EE0000?logo=redhat&logoColor=white)
![Fedora](https://img.shields.io/badge/Fedora-45-51A2DA?logo=fedora&logoColor=white)
![Windows](https://img.shields.io/badge/Windows-11_%2F_Server_2025-0078D4?logo=windows&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-ghcr.io%2Frustus%2Fsentora2027-2496ED?logo=docker&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/license-GPLv3-blue)

Sentora-2027 runs a web hosting server from the browser: websites, domains and DNS, mail, FTP, databases, backups and statistics, for any number of client accounts. Each account runs as its own Linux user, so one client can't reach another client's files, databases or mail.

This README is the whole guide: how to install and update the server, how to use every part of the panel as its administrator, and how it works behind the scenes. The [change log](CHANGELOG.md) lists every change in detail.

> [!IMPORTANT]
> Sentora-2027 needs a **fresh server** (Ubuntu 26.04, Debian 13, AlmaLinux 10, Rocky Linux 10, RHEL 10 or Fedora 45), or a Windows 11 / Windows Server 2025 PC for WSL. It can't upgrade an existing Sentora or ZPanel server, and the installer stops if it finds one. Websites move over from an old Sentora server with two commands (see [Moving websites from an old Sentora server](#moving-websites-from-an-old-sentora-server)).

## Contents

**Getting started**
- [What's included](#whats-included)
- [Requirements](#requirements)
- [Installing](#installing)
- [After installing](#after-installing)
- [Updating](#updating)

**Using the panel**
- [Groups, menus and permissions](#groups-menus-and-permissions)
- [Clients, packages and resellers](#clients-packages-and-resellers)
- [Panel settings (Sentora Config)](#panel-settings-sentora-config)
- [Websites and Apache](#websites-and-apache)
- [PHP versions](#php-versions)
- [Certificates](#certificates)
- [Email](#email)
- [DNS](#dns)
- [FTP, files and scheduled jobs](#ftp-files-and-scheduled-jobs)
- [Databases](#databases)
- [Backups and restoring](#backups-and-restoring)
- [Statistics](#statistics)
- [Monitoring and alerts](#monitoring-and-alerts)
- [Site Health](#site-health)
- [Dashboard and widgets](#dashboard-and-widgets)
- [Add-on modules](#add-on-modules)

**Behind the scenes**
- [The daemon](#the-daemon)
- [How the panel gets root work done](#how-the-panel-gets-root-work-done)
- [How accounts are kept apart](#how-accounts-are-kept-apart)
- [How Apache is protected](#how-apache-is-protected)
- [The web service API](#the-web-service-api)
- [Passwords](#passwords)
- [Logins and sessions](#logins-and-sessions)

**Reference**
- [Command-line tools](#command-line-tools)
- [Moving websites from an old Sentora server](#moving-websites-from-an-old-sentora-server)
- [Logs](#logs)
- [Troubleshooting](#troubleshooting)
- [Where things are](#where-things-are)
- [Releases](#releases)
- [Repository layout](#repository-layout)
- [License and credits](#license-and-credits)

---

# Getting started

## What's included

| Area | Software |
| --- | --- |
| Web | Apache 2.4 (event engine, HTTP/2); PHP 8.5 through PHP-FPM, and PHP 8.2, 8.3 and 8.4 per domain; PHP 7.1 only for moving old sites |
| Databases | MySQL 8.4, phpMyAdmin 5.2 |
| Mail | Postfix 3.10, Dovecot 2.4, Roundcube 1.7, SOGo 5.12 (calendars, contacts, ActiveSync), SpamAssassin and ClamAV |
| DNS | BIND 9.20 |
| FTP | ProFTPD 1.3.9, FTPS required |
| Statistics | GoAccess 1.12, Matomo 5; Webalizer and Visitors as add-ons |
| Security | Two-factor login, login lockout, fail2ban, a self-signed certificate at install and Let's Encrypt through Sencrypt |

The installer, the service settings and the PHP bundles come from the companion repository [RusTus/sentora-installers-2027](https://github.com/RusTus/sentora-installers-2027); this repository is the panel.

## Requirements

- A clean install of Ubuntu 26.04 LTS, Debian 13, AlmaLinux 10, Rocky Linux 10, Red Hat Enterprise Linux 10 (registered) or Fedora 45 (amd64), with no other control panel, web or mail server. A minimal install with only the SSH server is enough.
- Or Windows 11 (22H2 or newer) or Windows Server 2025 with virtualization switched on, for Sentora in WSL (see [On Windows](#on-windows)).
- Or a Linux host with Docker, for the ready-made image (see [In Docker](#in-docker)).
- Root access, and access from the server to github.com and api.github.com.
- A correct clock: the installer checks it and stops if it is more than 2 minutes off.
- A fully qualified host name for the panel (for example `panel.example.com`), ideally with an A record pointing at the server, and the server's IP address.

## Installing

On the new server, as root. One command for every supported system: it finds out which Linux this is, downloads that system's installer, checks it against the SHA-256 GitHub lists for it, and runs it (`--check` only downloads and checks):

```bash
wget -O sentora2027-install https://github.com/RusTus/sentora-installers-2027/releases/download/current/sentora2027-install
```

```bash
bash sentora2027-install
```

(A minimal Debian has no wget yet: `apt-get update && apt-get install -y wget` first; on the RHEL family and Fedora, `dnf install -y wget`. `curl -fLo sentora2027-install <the same address>` works too.)

Each system's own installer can also be run directly: `sentora2027-ub26.04`, `sentora2027-deb13`, `sentora2027-alma10` (AlmaLinux, Rocky Linux and RHEL 10) and `sentora2027-fedora` (Fedora 45), all on the same release. On Red Hat Enterprise Linux the server must be registered (`subscription-manager register`; Red Hat's free developer subscription works): the installer switches on CodeReady Builder and adds EPEL.

### On Windows

On Windows 11 (22H2 or newer) or Windows Server 2025, Sentora runs in **WSL, the Windows Subsystem for Linux** (Windows' own built-in way to run Linux), with its own Debian 13 or Ubuntu 26.04 inside. Either way below installs WSL if needed, the same Linux installer inside it, Sentora's ports in the Windows firewall, a startup task (Sentora runs whenever Windows runs, also with nobody logged in), a **Sentora Panel** shortcut on every desktop, a tray icon (green while the panel answers; one click opens it) and Sentora Status / Backup / Uninstall. The Windows administrator decides whether Windows Defender leaves Sentora's disk image alone.

- **Sentora2027Setup.exe**: [download it from the release](https://github.com/RusTus/sentora-installers-2027/releases/download/current/Sentora2027Setup.exe) and double-click it. It registers **Sentora 2027** in Settings > Apps and the Start menu; a newer setup updates it in place, and uninstalling there removes everything (backups stay). It is not signed, so SmartScreen shows "Windows protected your PC" once: More info > Run anyway.
- **Or PowerShell**, as administrator:

```powershell
Invoke-WebRequest -OutFile sentora2027-wsl.ps1 https://github.com/RusTus/sentora-installers-2027/releases/download/current/sentora2027-wsl.ps1
```

```powershell
powershell -ExecutionPolicy Bypass -File .\sentora2027-wsl.ps1
```

SELinux and AppArmor do not run inside WSL, and Windows updates restart the PC (and Sentora with it): for production hosting a Linux server is the better home.

### In Docker

A ready-made image, `ghcr.io/rustus/sentora2027` (Debian 13 inside), built and tested by GitHub on every release and published only when it passes. Nothing of Sentora is in the image itself: **the first time a container starts, it installs Sentora** with the Debian 13 installer, so every container gets its own passwords, keys and certificate. It runs without `--privileged`. On a Linux host with Docker (tested with Docker 29), as root:

```bash
docker run -d --name sentora --hostname panel.example.com \
  -e SENTORA_EMAIL=you@example.com -e TZ=America/Chicago \
  --restart unless-stopped --cgroupns=private --security-opt writable-cgroups=true \
  --tmpfs /run --tmpfs /run/lock --cap-add NET_ADMIN \
  -p 80:80 -p 443:443 -p 21:21 -p 61000-61099:61000-61099 -p 25:25 -p 465:465 -p 587:587 \
  -p 110:110 -p 143:143 -p 993:993 -p 995:995 -p 4190:4190 \
  ghcr.io/rustus/sentora2027
```

- `--hostname` is the panel's address (its DNS A record pointing at the host), `SENTORA_EMAIL` gets the server's alerts, `TZ` is the time zone (UTC when left out). `SENTORA_IP` sets the server's IP address (`public`, the default, finds it); `ADMIN_NAME`, `ADMIN_PHONE` and the other `ADMIN_*` fill zadmin's profile, which can also be done later in the panel.
- `--cgroupns=private --security-opt writable-cgroups=true` let systemd inside manage its services: the container gets its own branch of the host's cgroups and can write only there. `--cap-add NET_ADMIN` lets fail2ban ban addresses, in the container's own network only.
- FTP's passive ports are **61000-61099** in a container (Sentora's usual 60000-65535 overlaps the ports the host uses for its own connections, so Docker often can't publish it). Add `-p 53:53/tcp -p 53:53/udp` when this server is the DNS server for its domains and nothing on the host has port 53.

Then:

```bash
docker logs -f sentora
```

shows each step of the install (about 5 minutes on GitHub's machines) and ends with `Sentora is installed`. Passwords never reach the log; they are in the container, readable by root only:

```bash
docker exec sentora cat /root/passwords.txt
```

```bash
docker exec sentora sentora-post-install-check
```

Treat the container as the server: Sentora's settings are spread over the whole system, so its websites, mail and databases live in the container, not in separate volumes. It keeps everything through restarts and host reboots; `docker rm` deletes it all. Update it inside (`docker exec -it sentora sentora-update`; Debian's security updates install themselves), and back it up with the panel's backup, ideally to another server (Admin > Backup Config > Off-server backup). The post-install check warns that AppArmor is off: inside a container the host's Docker profile confines it instead, and Sentora's own AppArmor rules do not apply. For hosting other people's websites, a plain Linux server keeps every guard in place.

### What differs between the systems

The installers ask the same questions and set up the same panel. On Debian, the RHEL family and Fedora every service comes from the system's own packages, so its package manager and daily security updates keep them current:

| | Ubuntu 26.04 | Debian 13 | AlmaLinux / Rocky / RHEL 10 | Fedora 45 |
| --- | --- | --- | --- | --- |
| PHP (the panel's, and the sites' default) | 8.5 | 8.4 | 8.3 | 8.5 |
| Extra PHP versions (Sentora's builds) | 8.2, 8.3, 8.4, and 7.1 for moved sites | 8.2, 8.3, 8.5, and 7.1 for moved sites (7.1 without imap: Debian has no c-client) | 8.2, 8.4, 8.5, and 7.1 for moved sites (without imap) | Sentora's builds for Fedora 45 |
| Database server | MySQL 8.4 | MariaDB 11.8 (the panel calls it MySQL; its users log in with `mysql_native_password`, MariaDB's normal method) | MariaDB 10.11 (as on Debian) | MariaDB 12 |
| Apache, Postfix, Dovecot, BIND, ProFTPD | Ubuntu's | Debian's (Apache 2.4, Postfix 3.10, Dovecot 2.4, BIND 9.20, ProFTPD 1.3.8) | AlmaLinux's and EPEL's (Apache 2.4, Postfix 3.8, Dovecot 2.3, BIND 9.18, ProFTPD 1.3.9) | Fedora's (Apache 2.4, Dovecot 2.4, BIND 9.18, ProFTPD) |
| SOGo (groupware, ActiveSync) | yes | yes | yes (Sentora's own build) | yes (Sentora's own build) |
| Security updates | unattended-upgrades | unattended-upgrades | dnf-automatic | dnf5-plugin-automatic |
| Mandatory access control | AppArmor, on (BIND, MySQL, ProFTPD and ClamAV confined, with Sentora's paths) | AppArmor, on (BIND and ClamAV confined, with Sentora's paths) | SELinux, on (enforcing), with Sentora's labels | SELinux, on (enforcing), with Sentora's labels |

The commands, paths, services and users that differ are settings in the panel's database (hidden from Sentora Config), set by each installer: the panel's code names no distribution.

It takes about 30 minutes. The installer asks for the time zone, the panel's host name, the IP address and your contact details, installs and configures everything, and ends with a restart. Each download is checked against the SHA-256 GitHub lists for it.

To run it without the questions, give the answers on the command line and the contact details in the environment:

```bash
ADMIN_NAME='Jo Smith' ADMIN_EMAIL=jo@example.com ADMIN_PHONE=555-0100 ADMIN_ADDRESS='1 Main St' ADMIN_PROVINCE=TX ADMIN_POSTALCODE=75001 ADMIN_COUNTRY=US bash sentora2027-ub26.04 -t America/Chicago -d panel.example.com -i public
```

| Option | Meaning |
| --- | --- |
| `-t <time zone>` | Time zone, for example `Europe/London` |
| `-d <host name>` | The panel's host name (needs `-i`) |
| `-i <address>` | The server's IP address, or `local` / `public` for the detected one (needs `-d`) |

Settings at the top of the installer:

| Setting | Default | Meaning |
| --- | --- | --- |
| `SENTORA_RELEASE_TAG` | `current` | The release to install from; `latest` takes the newest, pre-releases included |
| `SENTORA_CORE_REPO`, `SENTORA_INSTALLERS_REPO` | the two RusTus repositories | Where to download from |
| `SENTORA_DOWNLOAD_URL` | empty | A folder on your own web server holding all the files instead of GitHub |
| `PHP_EXTRA_VERSIONS` | `8.2 8.3 8.4` (Debian: `8.2 8.3 8.5`) | Extra PHP versions to install (switched off until you switch them on) |
| `PHP_MIGRATION_VERSIONS` | `7.1` | Versions installed only for moving old sites (switched off) |

In order, the installer checks the clock, downloads and checks the files, asks its questions, updates Ubuntu (or Debian), installs the panel in `/etc/sentora/panel` (with its own Linux user `sentora`), then MySQL (MariaDB on Debian), a self-signed certificate, Postfix, Dovecot, SpamAssassin and ClamAV, Apache and PHP, the extra PHP versions, ProFTPD, BIND, the daemon's cron job, phpMyAdmin, Roundcube, SOGo, GoAccess, logrotate, fail2ban and Matomo, and finally locks down the permissions.

## After installing

Every generated password, including the panel's `zadmin` password, is shown at the end of the install and saved in `/root/passwords.txt` (readable by root only). The install log is left in `/root/`.

After the restart, wait 5 minutes for the first daemon run, then:

1. Run `sentora-post-install-check` and fix any FAIL line it shows.
2. Log in at `https://<panel name>` as `zadmin` and change the password (Account Information > Change Password).
3. Set an alert address: **Admin > Sentora Config**, *Alert email address*.
4. Once the panel name is in public DNS, issue a Let's Encrypt certificate with **Sencrypt SSL** (see [Certificates](#certificates)).
5. Run `sentora-smoke-test`: it makes a temporary client and uses the server the way a client does (about 2 minutes).

On a new server expect a few WARN lines from `sentora-post-install-check`: the self-signed certificate, isolation not testable with only one hosting account, the off-server backup switched off.

From another computer, these check what visitors can reach (download them from the installers release):

```bash
./sentora-web-probe panel.example.com      # headers, cookies and files that must not be served
bash sentora-tls-check.sh panel.example.com  # every TLS service, with testssl.sh
```

To move the panel to another address later (its DNS A record pointing to this server first), as root:

```bash
sentora-change-domain panel.new-example.com
```

It changes the name everywhere the installer put it (the panel and its Apache site, the host name, Postfix, Dovecot, SOGo, Matomo) and makes a new self-signed certificate; request a Let's Encrypt one in Sencrypt after. The changed files are kept as `<file>.before-<new name>`.

## Updating

**The panel.** The system installs its own security updates every day (Apache, PHP, MySQL or MariaDB, Postfix and the rest: unattended-upgrades on Ubuntu and Debian, dnf-automatic on the RHEL family and Fedora). The panel and the apps bundled in it (phpMyAdmin, Roundcube, Matomo, the file manager, GoAccess's pages) are updated with `sentora-update`, as root:

```bash
sentora-update --check      # is there a newer release?
sentora-update              # back up the panel, then update it (asks first)
sentora-update --rollback   # list the backups and restore one
```

It downloads `sentora-core-2027.zip` from the `current` release, checks its SHA-256, backs up the panel to `/var/sentora/backups/panel-before-update-<date>.tar.gz` (the last 5 are kept), updates the files while keeping this server's own (database settings and key in `cnf/`, `zsudo`, Matomo's settings, repository lists, add-on modules), registers new modules, sets Matomo up if this server never had it (`sentora-matomo-setup`), runs the daemon and `sentora-post-install-check`, and shows each step with a progress bar. Details go to `/var/sentora/logs/sentora-update.log`.

- A new version is recognised by its changed SHA-256; `--force` installs the same release again.
- `/etc/sentora/update.conf` can set `TAG="latest"` (the newest release, pre-releases included) or `URL=` (your own web folder holding the zip and a `SHA256SUMS`).
- It updates the panel only. Changes to service settings (Postfix, Dovecot, Apache and so on) are described in the change log with the commands to apply them.
- New core modules are given to Administrators only; give them to other groups in Module Admin.
- A rollback puts back the panel's files, not its database.

Once an hour the panel asks GitHub whether there is a newer release and shows administrators an "A panel update is available" notice. **Admin > Server Admin > Panel Update** does the same as the command: it shows the installed and the published release, updates (after your panel password) with each step shown as it runs, and lists the backups to go back to. Each request is logged in `/var/lib/sentora/panel-update.log`. If the panel does not answer after an update, go back from the console with `sentora-update --rollback`.

**Extra PHP versions** (7.1, 7.4, 8.1, 8.2, 8.3, 8.4) are not part of the panel: they come as bundles on the installers release. To add one, or to update one this server already has to the release's build, as root:

```bash
sentora-php-install 8.3
```

For a version that is already installed it shows the installed and the new build and asks first (`--yes` skips the question), keeps the version's settings, and puts the old files back if PHP does not start with the new ones.

---

# Using the panel

## Groups, menus and permissions

Every account is in a **group**, and a group decides which modules (pages) the account sees. There are three built-in groups:

| Group | Who | Gets by default |
| --- | --- | --- |
| Administrators | You (`zadmin`) and other administrators | Everything, including Server Admin and the administration tiles |
| Resellers | Accounts that sell hosting to their own clients | Everything a user has, plus the Reseller menu (Manage Clients, Package Manager, Manage Groups, Shadowing, Client Notice Manager, Theme Manager) |
| Users | Hosting clients | Their own domains, mail, databases, files, backups and statistics |

**Module Admin** (Admin > Module Admin) is where you decide this: one table lists every module with a switch (on for everyone, or off for everyone), its menu category, and one tick box per group. It also takes a `.zpp` module package to install (the daemon installs it within 5 minutes; new modules go to Administrators only). The administration tiles at the top of Module Admin (Apache Config, PHP Versions, DNS Config, Mail Config, FTP Config, Backup Config) are for administrators only and never appear in other menus.

The menus:

| Menu | Modules |
| --- | --- |
| Account Information | Dashboard, My Account (contact details, language, appearance, two-factor login; the support contact for your clients, or your reseller's), Change Password, Usage Viewer |
| Server Admin | Sentora Config, Module Admin, Server Monitor, Panel Update, Widget Admin, SpamAV Admin, System Log Viewer, Apache Status, Repo Browser, AutoIP Updater |
| Reseller | Manage Clients, Package Manager, Manage Groups, Shadowing, Client Notice Manager, Theme Manager |
| Domain Management | Domains, Sub Domains, Parked Domains, DNS Manager, Sencrypt SSL |
| Mail | Mailboxes, Forwards, Aliases, Distribution Lists, WebMail, SpamAV |
| Database Management | MySQL Database, MySQL Users, phpMyAdmin |
| File Management | File Manager, FTP Accounts |
| Advanced | Backup, Cron Manager, Protected Directories, View Logs, GoAccess, Matomo, Service Status, PHPInfo, FAQ (answers for clients; administrators and resellers add their own) |

## Clients, packages and resellers

**Package Manager.** A package is the set of limits a client gets: domains, sub-domains, parked domains, mailboxes, forwarders, distribution lists, FTP accounts, MySQL databases, disk space (MB) and monthly bandwidth (MB). 0 means none, *No limit* means unlimited. Make a package before the first client; deleting a package that clients use asks which package they move to. **Usage Viewer** shows each account its use against its package.

**Manage Clients.** Create, edit, switch off, delete and move clients:

- A new client needs a user name (also its folder name; it can't be changed later), a package, a group, contact details and a password, and can get a welcome email (with `{{fullname}}`, `{{username}}`, `{{password}}` and `{{controlpanelurl}}` filled in).
- A switched-off client can't log in, its FTP logins stop at once, and its websites go offline unless Apache Config keeps switched-off clients online. An account already logged in is logged out at its next page.
- Deleting a client removes its websites, mail and databases within the hour. Its own clients, packages and groups move to the account you choose (by default its reseller); an administrator among them, or an account with an administrator's module, goes to an administrator instead, never to a reseller.
- **Reset two-factor** (shown when the client uses it) is for a client who lost both phone and recovery codes: check who is asking first.

**Resellers.** An account in the Resellers group manages its own clients the same way, with its own packages and groups. What a reseller can give is limited by what it has:

- It can put clients in the Users group or in groups it made itself, never in Administrators.
- **Manage Groups** lets it make its own groups (a new group starts with the Users group's modules) and give them only modules its own group has, never a Server Admin module or an administration tile. A reseller can make sub-resellers this way (a group with Manage Clients).
- It never sees, shadows, edits or gives a group to an account "above" it: an administrator, or an account whose group has an administrator's module the reseller's group lacks.

**Shadowing.** *Log in as* opens the panel as one of your clients, to see what they see and help them; a bar at the top of every page takes you back to your own account, on the page you started from. An administrator can shadow every other account; a reseller only its own clients (and from there their clients). What you change while shadowing, you change for the client. Each start is recorded in `/var/lib/sentora/shadowing.log` (System Log Viewer > Panel security), and the page lists the recent ones.

**Client Notice Manager.** Notices at the top of clients' panels: information, warning, maintenance or outage, to all your clients, chosen packages or groups, or one client, with an optional start and end time and whether clients may close them. The panel's own notices (PHP end of support, panel updates) show there too. From the console: `sentora-notice send ...`.

**Support contact.** On **My Account**, an account that can have clients (an administrator, a reseller, or a sub-reseller whose group has Manage Clients) has a *Support for your clients* card: a support email, and optionally a phone number, a website and a one-line note (support hours, or a help desk address, which becomes a link). Its own clients see it on their My Account page in a *Support* card, with the email, phone and website as links. A client sees only its own reseller's contact: when that one is empty, nothing is shown.

**Branding.** **Reseller > Branding** gives the panel your own look for your clients (administrators and any account whose group has Manage Clients):

- **Look:** a company name (browser tab, footer, and the login page when there is no logo), a main colour (the login button and the line above the login box; the button text turns black or white, whichever reads better), a welcome line, and Light, Dark or Auto for the login page. A small picture of the login page follows what you type.
- **Logo:** PNG, JPG or WebP, up to 2 MB, and optionally a light version for dark pages (without one, the logo sits on a white tile there). The panel draws every upload again as a new PNG, so nothing but its pixels is kept. It shows up to 240 x 80 pixels on the login page and 36 pixels high in the top bar.
- **Contact:** the login page shows your support contact from My Account (email, phone, website) under the login box, and a small "Powered by Sentora" line.
- **Your panel address** (resellers): a name under one of your own domains, such as `panel.yourdomain.com`. Add a DNS A record for it pointing to the server; the daemon checks every 5 minutes, adds it to Apache, gets its Let's Encrypt certificate (renewed like the panel's) and shows *Live*. Until then the address answers "being set up": the login is never offered without https. A failed request is tried again after 1, 2, 4... hours. Password-reset and welcome emails send your clients to your address once it is live. A name that is a website on the server, the main panel name, another account's address or (for resellers) a name outside your own domains is refused.
- **Templates:** ready-made looks and your own saved ones, applied with one click (company name, colour, welcome line, Light/Dark/Auto and logos; your panel address stays). **ACME** is the example that comes with the panel. *Save your current look as a template* keeps up to 10 of your own (a holiday look, say). Applying a template keeps the look it replaces as *Your previous look*, so one click puts it back (and another click redoes it). Your saved templates are yours alone, and a template goes through the same checks as the look typed in. More ready-made templates can be added as folders in `modules/branding/assets/templates/<name>/` (`template.json` with title, about, name, colour, welcome and variant, plus `logo.png` and optionally `logo-dark.png`).
- **Who sees which brand:** after login, an account sees its own brand if it has one, else its nearest reseller's, else the administrator's (zadmin's), else the standard look. Before login, the login page shows the brand of the address it was opened at; the main address shows the administrator's. The administrator's page lists every reseller's address and its state.
- Nothing a reseller enters is used as HTML, CSS or a script: names and lines are plain text, the colour is a checked `#rrggbb`, and the logo is redrawn. Switching the module off in Module Admin removes every brand and takes the addresses out of Apache. The certificates are kept in `/etc/sentora/configs/ssl/branding/` (root only).

**Theme Manager.** The theme for you and your clients: *Modern*, in light, dark or automatic. A user's own choice (My Account > Appearance) comes first.

## Panel settings (Sentora Config)

**Admin > Sentora Config** holds the panel-wide settings in tabs (Panel, Security, Email, Logging, Other). The ones that matter most:

| Setting | Default | Effect |
| --- | --- | --- |
| Two-factor login | enabled | `disabled`; `enabled` (each account can turn it on in My Account); `enabled-forced` (every account must set it up at its next login) |
| Site not found falls back to panel login | on | An address with no site here (a typo, a domain not set up yet, the server's IP) opens the panel login, so new users always find it. Off: such addresses get a plain 404, so IP scanners and bots don't learn a panel is here; the more private choice once you know the panel's address. As root: `setso --set panel_fallback false && setso --set apache_changed true` |
| Serve panel on server IP | on | The panel also answers on `https://<server ip>` (matters only when the fallback above is off). Switch it off on an internet-facing server so scanners of the IP don't see the login |
| Shortest password | 10 | For panel passwords |
| Alert email address | empty (zadmin's address) | Where server alerts go |
| Alert disk use percent | 85 | Disk alert threshold (50 to 99) |
| Confirm panel password to show passwords | off | Show password (FTP, MySQL Users) asks for the panel password first |
| Shortest cron interval / Longest cron run | 5 min / 30 min | Limits for clients' scheduled jobs |
| Allow remote login forms | off | Leave off: logins only from the panel's own form |
| Sending method, SMTP settings, welcome email | | How the panel sends its own email |
| Debug mode, log mode | prod | Errors go to the log, not the page |
| Customer feedback | on | Once a day (and on the daemon's first run), a short report about this server to the Sentora-2027 project: see [Customer feedback](#customer-feedback). Off: `setso --set customer_feedback false` |

The panel's port, IP and SSL lines are here too; changing them rewrites Apache's settings. *Queue a full daemon run* runs every part of the daemon at its next run. Path and command settings (`zsudo`, `hosted_dir`, `httpd_exe` and others) are locked in the panel; change them as root with `setso --set <name> <value>`.

**AutoIP Updater** (administrators only) watches the server's public IP: when it changes, it updates the panel's IP and DNS records and emails the alert address.

### Customer feedback

To learn on what servers, in what parts of the world and how heavily the panel is used, each panel sends the Sentora-2027 project a short report on the daemon's first run and then once a day. It is on by default; **Sentora Config > Customer feedback** (or `setso --set customer_feedback false`) turns it off, and nothing more is sent.

What a report holds: a random ID made for this server (to tell reports apart), the panel's version and install date, the operating system, kernel, architecture and virtualisation, the CPU, cores, memory and disk, the PHP, database and Apache versions, the server's public IP (Sentora Config's) and internal IP, the time zone, how busy it is (load average, free memory, this month's website traffic and disk used of all accounts together), how many accounts, resellers, domains, sub-domains, parked domains, mailboxes, databases, FTP accounts and cron jobs there are, and which modules are on. Never a domain name, user name, email address, password, file or anything a client wrote. The report sent last is in `/var/lib/sentora/feedback-last.json`, and every send is in the daemon's log.

Where it goes: the address in `feedback-endpoint.txt` of the installers repository (read once a day; none there, nothing is sent), or a server's own **Sentora Config > Feedback > Feedback server** (its address, such as https://stats.example.com). The same server counts the Sentora-2027 add-ons' downloads and keeps their ratings and comments (Repo Browser shows them; an administrator rates an installed add-on there), and each report carries the computer's key (a one-way hash of its hardware ID, the same through reinstalls) besides the install's random ID. Without a feedback server nothing is sent, counted or rated, and the add-ons download from GitHub as always. The feedback server is in the installers repository's `feedback-server/public_html/` folder, ready to upload as a website.

## Websites and Apache

**Domains, Sub Domains, Parked Domains.** Each domain or sub-domain has a folder in the account's `public_html` and its own PHP version (when extra versions are switched on; chosen when adding it or later on its card). A parked domain has no site of its own. A new domain is live within 5 minutes (a parked one within the hour).

**Apache Config** (Admin > Module Admin > Apache Config) has two parts:

- **Global settings:** port, start pages, log formats, whether switched-off clients' sites stay online, open_basedir and the temporary and upload folders, PHP processes per account, how long vhost backups are kept, and *Extra directives* for every website and for the panel.
- **Per domain** (pick it from the list at the top): whether the site answers, open_basedir, port and IP, *Allow FollowSymLinks*, the SSL settings (which Sencrypt fills in) and the domain's own *Extra directives*.

Every entry is checked by Apache's own syntax test when you save it, and an SSL entry also has its certificate and key checked (files exist, key matches, no passphrase). If Apache still refuses a written file, only the faulty entry is switched off and everything else goes live; the page then says *Switched off: Apache refused it (…)*. Correct it and save, and it is used again. Lines a website may not use are kept but commented out: `php_value`/`php_flag` (PHP runs in PHP-FPM; use a `.user.ini`), `Include`, `LoadModule`, `User`, `Listen` and similar, piped logs, `SSLEngine` outside the SSL field, and anything that switches FollowSymLinks on.

**FollowSymLinks.** Websites may use symbolic links only to their own files (`SymLinksIfOwnerMatch`); `FollowSymLinks` would let one website read another's files. An old site whose `.htaccess` says `Options +FollowSymLinks` gets error 500 here (its error log says *Option FollowSymLinks not allowed here*). To move such a site:

1. In Apache Config, open the domain, switch on **Allow FollowSymLinks (for moving an old site)** and save: the site works again within 5 minutes.
2. Finish moving the site and check it works.
3. In the site's `.htaccess` files, change `Options +FollowSymLinks` to `Options +SymLinksIfOwnerMatch` (it works the same for the site).
4. Switch *Allow FollowSymLinks* off again and check the site once more.

Extra directives can't switch FollowSymLinks on: only that switch can, for one domain. `sentora-post-install-check` lists domains that still have it on.

**A site's `.htaccess`** may set rewrites, redirects, headers, caching, password protection and indexes (`AllowOverride AuthConfig FileInfo Indexes Limit Options=Indexes,MultiViews,SymLinksIfOwnerMatch`). PHP settings go in a `.user.ini` in the same folder.

## PHP versions

Domains run on the server's own PHP 8.5 unless they choose another version. **PHP Versions** (Admin > Module Admin) decides which extra versions domains may choose: tick one and the daemon starts its service and offers it in Domains; untick it and its domains fall back to PHP 8.5.

| Version | Security fixes until | Notes |
| --- | --- | --- |
| 8.5 | 2029-12-31 | The server's own (Ubuntu's packages, updated daily) |
| 8.4 | 2028-12-31 | Bundle in `/opt/sentora-php/8.4` |
| 8.3 | 2027-12-31 | Bundle in `/opt/sentora-php/8.3` |
| 8.2 | 2026-12-31 | Bundle in `/opt/sentora-php/8.2` |
| 8.1 | ended | A step for old apps on their way to a supported PHP (Snipe-IT 6, Magento 2.4.4 and later): switched off, asks before switching on. Ubuntu's own libraries, with the add-ons |
| 7.4 | ended | A step for old apps on their way to PHP 8 (osTicket 1.14 to 1.15, Snipe-IT 5, Magento 2.3): as 7.1, with its own OpenSSL 1.1, libxml2 and ICU and the add-ons |
| 7.1 | ended | Only for moving old sites: switched off, and asks before switching on. Also has intl and xsl (Magento 2) and the add-ons apcu, redis, memcached, imagick and imap |

Domain lists mark a version whose fixes have ended (red) or end within 90 days (yellow), and show under each domain's choice which PHP it really runs on, loaded after the page: *Running PHP 7.1.33* (green: Apache was given that version and its PHP-FPM socket is there), *Changing within 5 minutes* (amber: the daemon has not applied a new choice yet) or *PHP 8.2 is not running* (red). The daemon notes which PHP socket Apache was given for each domain whenever Apache runs with a new configuration (`/var/lib/sentora/php-live.json`); each day the daemon logs those domains and sends their owners a notice. Every version has LDAP, for sites that log users in against Active Directory. 7.4 and 8.1 are not installed by default: PHP Versions shows the command (`sentora-php-install 7.4`, `sentora-php-install 8.1`). Old apps usually have to be upgraded through their own versions one at a time, and those need the PHP in between: for example osTicket 1.10 → 1.14 (PHP 7.4) → 1.17 (PHP 8.x), Snipe-IT 3 → 5 (PHP 7.4) → 6 (PHP 8.1) → 7, Magento 2.2 → 2.3 (PHP 7.4) → 2.4.4 (PHP 8.1). PHP 7.1 carries its own OpenSSL 1.1, libxml2 and ICU, kept private to it; its add-ons use Ubuntu's own libraries and are switched on only when they load (`sentora-php-install 7.1` does that, and the installer). Each account's pool runs a set number of PHP processes (**PHP processes per account** in Apache Config, 10 by default): a busy shop can use them all, and PHP's log then says *server reached max_children*. Bundles are updated with `sentora-php-install <version>` (see [Updating](#updating)).

**Updates of the extra versions.** They are Sentora's own builds, not the system's packages, so apt and dnf do not update them. Once a day the daemon compares each one with the builds on the release (`php-bundles.json`, the list of every build with its version and SHA-256) and with php.net. When the release has a newer build, the administrators get a notice and PHP Versions' **Updates** card has an **Update** button: the daemon then installs it within 5 minutes (checked against its SHA-256, tested with this server's settings, the old files back if it does not start; the sites on that version pause for a few seconds). When php.net has a release that is not built yet, the notice says so; it is offered as soon as its build is published. Nothing is compiled on the server. The builds are made on GitHub (sentora-installers-2027, **Actions > PHP bundles > Run workflow**: the system, and the versions, such as `8.4` for the newest 8.4); `setso --set php_bundle_maintainer 1` adds a link to it to the notice on your own servers.

**Older logins.** PHP 7.1 can't log in to MySQL 8.4 with its default method, so the MySQL users of sites on PHP 7.1 use the older `mysql_native_password`, which MySQL only allows once it is switched on (the move from an old server does that). The *Older logins* card on PHP Versions lists those users (with **Use the modern login**, which keeps each password) and the mailboxes still on old MD5 passwords, and switches MySQL's older method off once no user needs it. MySQL Users marks them *Older login (PHP 7.1)*, and `sentora-post-install-check` warns while the method is on. Move each site to PHP 8, then retire its older login.

## Certificates

The installer makes a self-signed certificate (10 years, for the panel name and the server's IPs) in `/etc/sentora/configs/ssl/`. Browsers and mail programs warn about it until you replace it.

**Sencrypt SSL** issues and renews Let's Encrypt certificates (checked daily and renewed when a third of a certificate's lifetime is left: 30 days of today's 90, and still right when Let's Encrypt moves to 64 days in February 2027 and 45 in February 2028), makes self-signed ones, takes third-party certificates and makes certificate requests (CSRs). An administrator can secure the panel's own name, as soon as it resolves to this server from the internet and port 80 is reachable. Within 5 minutes the daemon copies the panel's certificate to Postfix, Dovecot and ProFTPD too (the self-signed one is kept as `selfsigned.*`). To use your own CA instead, replace `sentora.crt`, `sentora.key` and `sentora-chain.crt` in `/etc/sentora/configs/ssl/` and reload the services. The server monitor alerts 14 days before a certificate expires.

A main domain's certificate covers its `www.` name too, unless public DNS has no address for `www.` (then it covers the domain only, and Sencrypt says so: one name Let's Encrypt can not check fails the whole request). It works behind a router: the panel's IP may be a local one. When Let's Encrypt refuses, Sencrypt says why in plain words with what to do, followed by Let's Encrypt's own message:

| Let's Encrypt found | Usually | What to do |
| --- | --- | --- |
| No address in public DNS (NXDOMAIN, *no valid A records*) | The name, often `www.`, has no DNS record | Add an A record pointing to the server's public address (behind a router: the router's) |
| Could not connect (*Timeout during connect*, *Connection refused*) | Port 80 is not reachable from the internet | Forward port 80 to the server and allow it in any firewall; renewals need it too |
| Wrong answer (*Invalid response … 404*) | DNS points to another server, or the site blocks `/.well-known/acme-challenge/` | Fix the DNS record or the `.htaccess` rule or redirect |
| Too many attempts (*rateLimited*) | Several failures or certificates in a short time | Wait until the time it names, fix the cause first |
| CAA record, or an internal name such as `.local` | The domain does not allow Let's Encrypt, or is not public | Add `0 issue "letsencrypt.org"`; internal names need a self-signed certificate |

## Email

| Service | Address | Notes |
| --- | --- | --- |
| Roundcube webmail | `https://<panel name>/etc/apps/webmail/` | Settings > Password changes that mailbox's password |
| SOGo (mail, calendar, contacts) | `https://<panel name>/SOGo` | CalDAV and CardDAV for calendar and contact apps |
| ActiveSync (phones, Outlook) | `https://<panel name>/Microsoft-Server-ActiveSync` | On or off in Mail Config |
| IMAP / POP3 | 993 / 995 (TLS), 143 / 110 (STARTTLS) | |
| Sending | 587 (STARTTLS) or 465 (TLS) | Login required |
| Incoming | 25 | |

Clients make mailboxes, forwarders, aliases and distribution lists in the Mail menu. Each mailbox has its own password: its user changes it in webmail, and the account owner can only set a new one, never see it. **Mail Config** (administrators) sets the largest mailbox, whether mail of deleted domains is removed, and SOGo's settings (ActiveSync, language, time zone, limits); the daemon applies them within 5 minutes.

**Spam and viruses.** SpamAssassin and ClamAV check every message while the sender is connected. Viruses are refused; spam above the reject score (15) is refused; other spam (score 5 and up) is marked `***SPAM***` and filed in the mailbox's Spam folder, or left in the inbox, as each mailbox's owner chooses in **Mail > SpamAV** (unless you don't allow it). If a scanner is down, mail is still accepted. **SpamAV Admin** shows both scanners' status and signature dates, updates them on request and holds every setting. ClamAV needs about 1.5 GB of memory and is left off on smaller servers; switch it on in SpamAV Admin when there is room.

## DNS

BIND answers for the hosted domains only (no recursion; zone transfers only to the servers you list). Clients edit their records in **DNS Manager**; within 5 minutes the daemon writes each changed zone, checks it with `named-checkzone`, and reloads BIND (a zone that fails the check is not loaded). **DNS Config** (administrators) has the default TTLs, the record types allowed, zone transfers, BIND start/stop/reload, the BIND log, and tools to reset, fill in or purge records.

## FTP, files and scheduled jobs

- **FTP Accounts:** clients make FTP logins for folders of their own account. FTPS (TLS) is required, passive ports 60000 to 65535, each login locked in its folder, and files are written as the account's own Linux user. The owner can show a login's password (logged). **FTP Config** (administrators) lists every FTP account and the connection details.
- **File Manager:** a file manager in the browser, limited to the account's own folder: upload, edit, zip and unzip.
- **Cron Manager:** a PHP script from the account's folders, run on a schedule as the account's own user with its domain's PHP version, within the limits in Sentora Config (shortest interval, longest run). The last output is kept in the account's `tmp/cron-<id>.log`.
- **Protected Directories:** a username and password on a folder of a website (Apache basic authentication); the passwords are kept outside the website.

## Databases

**MySQL Database** creates and deletes databases (named `<account>_<name>`); **MySQL Users** creates users, gives them databases, and resets or shows their passwords; **phpMyAdmin** opens with a MySQL login. MySQL listens on this server only. Database sizes count towards the account's disk space.

## Backups and restoring

**Account backups.** In **Advanced > Backup** a client makes a backup of its websites and databases (not its mail) in the background and downloads it when it is ready. Backups are zips in the account's `backups/` folder (so its FTP login can reach them). In **Backup Config** (administrators) you decide whether clients may make them, whether every account gets one each day, and after how many days old ones are deleted (30). To put one back, as root:

```bash
sentora-restore <account>                 # lists its backups, newest first
sentora-restore <account> <backup.zip>    # web files and databases
```

`--files`, `--databases`, `--exact` (also remove files that are not in the backup), `--yes`. It backs up the account as it is first.

**Clients' own FTP backups.** On the same Backup page a client can have a backup sent each day to its own FTP server (FTPS by default), at the hour it chooses, keeping as many copies as it chooses. Backup Config can switch this off, and decides whether private network addresses are allowed (loopback and this server's own services never are).

**Off-server backup** (Backup Config > Off-server backup) copies everything needed to rebuild the server to another machine every night: every account's files and backups, every MySQL database (dumped at that moment, the panel's own included) with the MySQL users, the panel and service settings with the encryption key, Let's Encrypt, and the mailboxes if ticked. It uses rsync over SSH (with a password, or the SSH key made by `sentora-offsite-backup --setup`); each night is a dated snapshot in which unchanged files are hard links to the night before, so only changes are sent and stored. *Test connection* and *Back up now* run within 5 minutes; you get an alert when it has not succeeded for 26 hours. The backup machine holds the keys to everything, so use one you trust.

**Restoring from the off-server backup.** Backup Config > Restore reads the snapshots (also another server's, so a new server can restore an old one: set up the off-server backup with the same machine and folder first). Restore one account (it must exist here with the same name) or the whole panel (type RESTORE; best on a fresh install). What is here before is always saved in `/var/sentora/backups/pre-restore-*`. From the console: `sentora-panel-restore --list`, `--account`, `--full`.

## Statistics

| Module | What it shows |
| --- | --- |
| **GoAccess** | Traffic reports per domain, updated hourly, with a year of history. Administrators also see the panel and all domains together |
| **Matomo** | Full analytics per domain, opened without a second login (each account has its own view-only Matomo user) |
| **Webalizer Stats**, **Visitor Stats** (add-ons) | Classic reports per domain, hourly |
| **View Logs** | Each domain's own access and error logs, live |

Matomo gets its visits one of two ways, chosen per domain on the Matomo page: *server logs* (the default: the hourly job imports new log lines, leaving out bots and static files) or *tracking code* (only visits from the Matomo snippet on the site's pages). Each import writes a line per domain to `/var/sentora/logs/matomo-import.log`: look there first when Matomo shows nothing.

## Monitoring and alerts

Every 5 minutes the daemon checks the server and emails the alert address (Sentora Config) when:

- a disk is fuller than the alert percentage (85%);
- a service stopped: Apache, PHP-FPM, MySQL, Postfix, Dovecot, ProFTPD, BIND, fail2ban, cron, SOGo or memcached;
- more than 100 messages wait in the mail queue (often a website sending spam: run `mailq`);
- the off-server backup has not succeeded for 26 hours;
- a certificate expires within 14 days.

One email when a problem starts, a reminder every 24 hours, one when it is resolved; each is also written to `/var/sentora/logs/alerts.log`.

To look yourself: **Server Monitor** (CPU, load, memory, network, disks, busiest processes and services, live), **Apache Status** (requests, workers, slowest requests), **System Log Viewer** (every server log, live, grouped: PHP, panel, panel security, domains, FTP, DNS, Apache, mail, MySQL, system) and **Service Status** (which services answer, and the daemon's last and next run).

## Site Health

**Domain Management > Site Health** shows each account whether its websites work, with a card per site (worst first) and plain reasons with a link to the module that fixes them:

- **Not working**: the home page answered with a server error (5xx), did not answer, the certificate expired, or the site's PHP is not running.
- **Needs attention**: an answer other than the home page (4xx), slow (over 3 s), a certificate expiring within 14 days or self-signed, a PHP version without security fixes, fatal PHP errors today, or more than 5% of today's visits getting a server error.

Each card shows the last answer and how long it took (with the last 24 checks), the certificate, the PHP the site runs, today's PHP errors and requests (with the last 7 days). **Details** lists the last day of checks, the latest distinct PHP errors from the site's error log (the account's folder shortened to `~/`), and today's pages not found (404) and failing (5xx). The **Site health** dashboard widget shows the same, worst first.

Every hour the daemon asks each live domain and sub-domain for its home page from the server itself (over HTTPS when the site has a certificate of its own here, which it then reads), and reads only the new lines of the site's logs (`/var/sentora/logs/domains/<user>/<domain>-access.log` and `-error.log`). Its own requests (user agent `Sentora-SiteHealth`) are left out of the counts. The summaries are kept in `/var/lib/sentora/sitehealth/` (root's, readable by the panel): 30 days of daily counts and a week of hourly checks. The check runs on the server, so it does not see problems outside it (DNS, the router).

## Dashboard and widgets

Every login lands on the **Dashboard**, where each account arranges its own widgets (getting started, usage, domains, security, backups, spam and viruses, and for administrators the server). **Widget Admin** decides which widgets each group may use and which new dashboards start with, resets every dashboard, and takes new widgets as a `.zip` (`widget.xml`, `widget.php`, optional `widget.css`; the format is in `etc/dashboard-widgets/README.md`). A widget's code runs inside the panel, so upload only widgets you trust. Switching the Dashboard off in Module Admin sends logins to the home page.

## Add-on modules

**Repo Browser** lists module repositories (the Sentora-2027 add-on repository by default) and installs, updates and removes their modules; the daemon does it as root within 5 minutes. Every download is checked against the repository's `SHA256SUMS`. A module with the name of a core module is never installed over it.

| Module | What it does |
| --- | --- |
| PHP-Adminer | Adminer for an account's own MySQL databases, with its own MySQL users only |
| Webalizer Stats | Webalizer reports per domain (builds its program on install) |
| Visitor Stats | Visitors reports per domain (builds its program on install) |
| Log Forwarder | Sends website logs (and, for administrators, server logs) live to your own log server (ELK, OpenSearch, Grafana Loki, Graylog) as syslog over TLS, through rsyslog; a client's forwarding counts towards its bandwidth and only goes to addresses on the internet |
| QuickFig | WordPress settings as a form instead of editing wp-config.php (site address, debugging, security, memory, revisions, updates, WordPress's scheduled tasks run by the server), rescue tools for a broken site (stuck maintenance notice, switching a plugin off, cache, new secret keys, database test, debug log), earlier versions with undo, and a Magento shop's cache, maintenance mode and its own tools (cache:flush, reindex, deploy mode) run in the background as the account's own user; a switch to https (addresses and the links in the database, with WP-CLI) and hourly reminders (debug mode left on, a stuck maintenance notice), a Magento shop's junk search terms deleted weekly, a crawler blocker for every website (bad bots, scrapers posing as an old Chrome, robots.txt rules for the app it finds: WordPress, WooCommerce, Magento, OpenCart, PrestaShop, Joomla, Drupal and more), websites sharing the same files pointed out, who visited each website in the last 24 hours (with Block), hardening switches (no PHP in upload folders, xmlrpc.php off, security headers, always https, HSTS, one www address), a permissions check with Fix, and WordPress's updates at a glance; every file is handled by a worker confined to the account's own folder |
| Apps | One-click WordPress, OpenCart and Snipe-IT into a website or a folder of it: the panel makes the database and MySQL user (counted in the package) and installs the app as the account's own user from a checked package, with the admin the owner chooses; no setup page is left open, OpenCart is made safe as it asks (install folder removed, storage moved out of the website, admin renamed), and Remove takes the files and the database away; **Deploy from Git** puts the account's own app from a repository (public, or private with a deploy key) into a website, with Composer, a database in its .env, Laravel's steps, Deploy now, Roll back and a webhook that deploys on push |
| Payment Plans | Billing for administrators and resellers: package prices, clients' monthly or yearly plans, new clients on a priced package billed by themselves, numbered invoices with reminders, payment with PayPal or Stripe (the seller's choice) into the seller's own account; unpaid past the grace days switches the client off, paying switches it on again; the seller's figures at a glance and a history of every invoice |
| Web Hardening | ModSecurity (Apache, with the OWASP rules) and Snuffleupagus (in PHP): each off, watch only or on, what they caught, the domains, accounts and rules left alone |

The add-ons live in the [`sentora-repo` folder](https://github.com/RusTus/sentora-installers-2027/tree/master2027/sentora-repo) of the installers repository. To add your own: put `<Name>.zpp` there, add `<Name>` to `packages.txt`, run `sha256sum *.zpp > SHA256SUMS`, and push to `master2027`. A module's code runs inside the panel, so add only modules you trust.

**Updates:** every hour the daemon reads each installed add-on's `<Name>.xml` from its repository. When its `<latestversion>` is higher than the installed version, the administrators get a notice (*A module update is available*), and Repo Browser shows the module under **Updates** with an **Update** button. The notice goes away once the module is updated. Opening Repo Browser checks at once. **Check all repositories now** (above the module list) asks every repository at once and has the daemon read their lists again (`zppy update`, within 5 minutes); as the page is drawn, each repository's answer fills in: how many modules it has, the ones that are new since the list was last read (with their description and version) and the ones it no longer offers. To publish an update, raise `<version>` in the module's own `module.xml` inside the `.zpp` and `<latestversion>` in `<Name>.xml` to the same number, then update `SHA256SUMS`.

---

# Behind the scenes

## The daemon

Most of what the panel changes is done by the **daemon**: `bin/daemon.php`, run by cron as root every 5 minutes (`/etc/cron.d/zdaemon`), with its last run in `/var/sentora/logs/daemon-last-run.log`. That is why a change in the panel can take up to 5 minutes. Only one run works at a time: a run that finds another one busy waits for it (up to 15 minutes, then it is skipped), so a long run never overlaps the next. Each module adds its own work through hooks, run every time, every hour or every day (by elapsed time since the last such run):

| When | What |
| --- | --- |
| Every 5 minutes | Account users, PHP-FPM pools, FTP owners and cron jobs brought in line; Apache's settings rewritten, tested and reloaded when something changed; DNS zones written; queued module installs, widget installs, off-server backup and restore requests carried out; client FTP backups due; SOGo, SpamAV and Matomo settings applied; FTP logins of switched-off clients stopped; disk use and bandwidth counted; Let's Encrypt certificates copied to mail and FTP; the server checked for alerts; the public IP checked (AutoIP); the server's own addresses checked (see below) |
| Every hour | Account files given back to their owner and folders closed again; GoAccess and Matomo fed the new log lines; the check for a newer panel release |
| Every day | Scheduled account backups and purging old ones; Let's Encrypt renewals; PHP end-of-support notices |

**When the server's IP changes.** The websites' Apache settings name their domains, so they keep working on a new IP. Two things depend on the address itself: the panel answering on the server's IP, and the panel's IP setting (Sentora Config, used for new DNS records). The daemon follows a change by itself within 5 minutes. It rewrites Apache's settings whenever the server's addresses change. When the panel's IP is a local address the server no longer has and it now has exactly one local address, the panel's IP becomes that one, and the DNS records that pointed to the old address follow. That is logged in `alerts.log` and emailed to the alert address. A public panel IP (reached through a router) is left to AutoIP. With several local addresses it changes nothing and says so once, with the command to run.

To run it at once, use **Run the daemon now** in Sentora Config > Daemon (administrators; its report goes to `/var/sentora/logs/daemon-panel-run.log`, and a press while one it started is busy is dropped). From the console, including the hourly part: `setso --set daemon_hourrun 0 && php /etc/sentora/panel/bin/daemon.php`.

## How the panel gets root work done

The panel's PHP runs as the Linux user `sentora` in its own PHP-FPM pool, which runs only the panel's own scripts. Its code belongs to root, so the panel can't change itself. When something needs root, the panel only writes a request (a setting, a table row or a file), and the root daemon checks it again and carries it out:

- `apache_changed`: rewrite the websites' Apache settings (Domains, Apache Config, PHP Versions, Sencrypt, Sentora Config);
- `dns_hasupdates`: write those DNS zones;
- the module queue: Module Admin and Repo Browser installs (`zppy` commands);
- files in `/var/lib/sentora/tmp/`: widget installs, restores;
- off-server backup and FTP backup requests, the older-logins switch-off.

For the few things that can't wait 5 minutes there is `zsudo`, a small root program only the panel's user can run (`root:sentora 4750`). It clears the environment and hands one of a fixed list of command lines to `atd`: reload or restart Apache, start/stop/reload BIND, end switched-off clients' FTP sessions, run the daemon now (Sentora Config > Daemon), and the panel update's check, update and rollback. Nothing else is accepted. `sentora-update` rebuilds it when its source changes.

## How accounts are kept apart

- **One Linux user per account**, `web<account id>`, with its own group, no shell, and a home folder `/var/sentora/hostdata/<account>/` (with `public_html`, `backups`, `tmp`). The folder belongs to that user and is closed to everyone else; access lists give the panel read-write and Apache read-only access. `hostdata` itself is `711`, so no one can list the accounts.
- **One PHP-FPM pool per account and PHP version**, running as the account's user, with `open_basedir` set to its home and its own temporary, upload and session folders (`/run/php/web<id>.sock`, or `/run/sentora-php/web<id>-<version>.sock` for extra versions, each its own). A domain with *open_basedir* on is limited to its own folder; every request names its limit, so a worker never keeps another domain's.
- **FTP logins and cron jobs** run as the account's user (cron jobs also with a time limit and lower priority).
- **Every hour** the daemon gives files back to the account's user (files written by the panel, root or Apache) and closes the folders again.
- **Log analysers** (GoAccess, Webalizer, Visitors) run as `sentora-stats`, which can read nothing else; root hands them the log lines.
- **MySQL** users of one account only get that account's databases; **mail** is stored under `/var/sentora/vmail` as the `vmail` user.

### SELinux and AppArmor

They stay on, on every Linux system: Sentora is made to work with them, never by switching them off. On AlmaLinux, Rocky, RHEL and Fedora SELinux is enforcing, with Sentora's folders labelled by the installer and a few rules of its own (`preconf/selinux/sentora.cil` in the installers repository). On Debian and Ubuntu AppArmor confines BIND and ClamAV (and on Ubuntu MySQL and ProFTPD too), with Sentora's paths added to each profile's local file (`/etc/apparmor.d/local/<profile>`, between `# BEGIN Sentora` and `# END Sentora`); the system's own profiles are never changed or disabled. The post-install check shows which services run confined and anything refused since the start. Servers installed before release 2027.71 have AppArmor off; the check says so.

## How Apache is protected

From a website's own `.htaccess`:

1. **AllowOverride** only lets a site set authentication, file types, indexes, access and `SymLinksIfOwnerMatch`.
2. **A `<Location "/">` block in each website's settings**, which `.htaccess` can't override: a request handed to any PHP-FPM socket but the account's own gets no PHP settings and no script, and status pages come out blank.
3. **`sentora-guard.lua`** (mod_lua) refuses (403) a handler that points at `server-status`, `server-info`, a proxy, or another account's or the panel's PHP-FPM socket. Refusals are logged in the domain's error log.

From the administrator's own settings (Apache Config):

1. Each entry is tested with `apache2 -t` when saved; SSL entries also have their certificate and key checked.
2. When the daemon writes the websites' settings, each entry sits between `# BEGIN/END SENTORA CUSTOM` markers. If `apachectl configtest` fails, the old file stays, the entry Apache names is switched off, and the file is written again (up to 6 times). If no entry is to blame, the previous file stays and Apache Config shows Apache's error.
3. If Apache does not run after a reload, the previous file is put back and Apache restarted; the domain named in Apache's error has its entries switched off.

So a typing mistake in Extra directives or a bad certificate can't take every website down; it only switches off that one entry.

## The web service API

Programs (a billing system, a reseller's own order form or website) manage the panel through `https://<panel>/api/<module>`, with requests sent as POST in XML or JSON. Every answer carries the API's version (2), a response code and its content, in the same format as the request.

**Keys.** On **Advanced > API Access**, an administrator or reseller makes one key per program:

- a key is shown once when it is made; the panel keeps only its fingerprint (SHA-256);
- it can be limited to the addresses the program connects from (IPs or ranges such as `203.0.113.0/24`);
- it can be switched off or deleted on its own, and the page shows when and from where it was last used;
- every request is logged (when, which key, from where, the request, its answer), kept 90 days. Resellers see their own keys' requests; administrators see all, including refused ones.

The server's own key (`apikey`; as root, `setso --show apikey` prints it, and API Access can replace it) still works as an administrator's key, for programs set up before keys of their own existed.

**What a key may reach.**

- **A reseller's key acts as the reseller:** its own clients (and their clients, at any depth), its own packages, the groups its Manage Clients page offers, and only the modules its own group has. It can never touch its own account, another reseller's clients, an account above it, or an administrator.
- **An administrator's key** reaches every account.
- **No key** deletes, switches off, suspends, changes or sets the password of an **administrator**, makes one, or makes a login link for one: a leaked key can not lock you out or take the panel over.
- **Server-wide requests** need an administrator's key: the settings list, the server's totals, its services and ports, and every account's domains.
- **Package limits apply:** a request that adds a domain, sub-domain, parked domain, mailbox, forwarder, database or FTP account is refused when the account's package allows no more, exactly as on the panel's pages.

**Format.** XML:

```xml
<xmws><apikey>sk_...</apikey><request>GetClient</request><content><uid>12</uid></content></xmws>
```

JSON (answered in JSON):

```json
{"apikey": "sk_...", "request": "GetClient", "content": {"uid": 12}}
```

Answers: `<xmws><version>2</version><response>1101</response><content>...</content></xmws>`, or `{"version": 2, "response": "1101", "content": {...}}`. Codes:

| Code | Meaning |
|---|---|
| 1101 | done (or, with a plain message, an internal error: the server's logs say why) |
| 1102 | no such request in that module |
| 1103 | wrong key (or a key used from an address it does not allow) |
| 1104 | too many wrong keys from this address: wait 15 minutes |
| 1106 | no request named |
| 1107 | a value that is not allowed, or an account, package or item the key may not reach (the answer says which) |
| 1108 | not allowed with this key (an administrator's request, or a module the reseller's group lacks) |

Sizes are in MB as the panel counts them (1 MB = 1,024,000 bytes); `-1` is "no limit". Answers are UTF-8 with every value escaped; secrets are never sent (password hashes, the API key, the mail server's password and tokens show as `********` in the settings list).

**Requests.** `uid` is an account's ID; a request about "an account's" things takes the key's own account or one it may reach.

- **manage_clients:**
  - `GetAllClients` (`uid`: whose clients, default the key's own account);
  - `GetClient` (`uid`): details (never the password hash), and why it was suspended;
  - `GetClientUsage` (`uid`): use against the package's limits, this month's bandwidth;
  - `CreateClient` (`resellerid`: a reseller's key makes its own clients only; `username`, `packageid`: the reseller's own; `groupid`: never Administrators; `fullname`, `email`, `password`, optional `address`, `postcode`, `phone`, `sendemail`, `emailsubject`, `emailbody`): answers the new `uid`;
  - `UpdateClient` (`uid`, any of `fullname`, `email`, `phone`, `address`, `postcode`);
  - `ChangePackage` (`uid`, `packageid`: one of the client's reseller's packages);
  - `SuspendClient` (`uid`, `reason`: one line, such as "not paid") and `UnsuspendClient` (`uid`);
  - `EnableClient`, `DisableClient` (`uid`);
  - `DeleteClient` (`uid`, optional `moveid`: who gets its clients, packages and groups);
  - `UsernameExists` (`username`);
  - `CreateLoginLink` (`uid`): see below.
- **packages:**
  - `GetAllPackages` (optional `uid`), `GetPackage` (`packageid`), `GetPackageId` (`packagename`): each with its limits;
  - `CreatePackage` (`name`, `php` true/false, and every limit: `domains`, `subdomains`, `parkeddomains`, `mailboxes`, `forwarders`, `distlists`, `ftpaccounts`, `mysql`, `diskspacemb`, `bandwidthmb`): answers `packageid`;
  - `UpdatePackage` (`packageid`, any of those): its clients get the new limits at once;
  - `DeletePackage` (`packageid`, `moveto`: another of your packages, for its clients).
- **domains:** `GetDomainsForUser` (`uid`), `CreateDomain` (`uid`, `domain`, `destination` or `autohome` 1), `DeleteDomain` (`domainid`), `GetAllDomains` (administrators).
- **sub_domains:** `GetSubDomainsForUser` (`uid`), `CreateSubDomain` (`uid`, `subname`: a name under one of the account's own domains, `subdirectory` or `autohome` 1), `DeleteSubDomain` (`domainid`).
- **dns_manager:** `GetAllDNSRecords`, `CreateDNSRecord`, `DeleteDNSRecords` (`uid`, `domainName`: one of the account's own domains; `hostName`, `type`, `target`, `ttl` 60 to 604800).
- **mailboxes:** `GetMailboxes` (`uid`); `CreateMailbox` (`uid`, `address`: a full address on one of the account's own domains, `password`): answers `mailboxid`; `SetMailboxPassword` (`mailboxid`, `password`); `DeleteMailbox` (`mailboxid`, optional `dropaliases`, `leavelists` true: also remove the aliases that deliver to it and take it off mailing lists; its forwarders go with it).
- **forwarders:** `GetForwarders` (`uid`); `CreateForwarder` (`uid`, `mailboxid` or `address` of one of the account's own mailboxes, `destination`, `keepcopy` true/false): answers `forwarderid`; `DeleteForwarder` (`forwarderid`).
- **mysql_databases:** `GetDatabases` (`uid`): name, size, users; `CreateDatabase` (`uid`, `name`: letters, digits and -; the panel puts "<user name>_" before it): answers `databaseid` and the full name; `DeleteDatabase` (`databaseid`): the database and everything in it.
- **mysql_users:** `GetDatabaseUsers` (`uid`): name, access, databases (never passwords); `CreateDatabaseUser` (`uid`, `name`, `databaseid`: its first database, `access`: `any`, `localhost` or an IP address): the panel makes the password and answers it once, with `userid` and the full name; `SetDatabaseUserPassword` (`userid`, `password`); `GrantDatabase`, `RevokeDatabase` (`userid`, `databaseid`: the same account's); `DeleteDatabaseUser` (`userid`).
- **ftp_management:** `GetFtpAccounts` (`uid`): name, folder, access (never passwords); `CreateFtpAccount` (`uid`, `username`: the panel puts "<user name>_" before it, `password`, `access` RW, RO or WO, `folder`: an existing folder in the account's home, or empty for a new one named after the user): answers its ID and full name; `SetFtpPassword` (`ftpid`, `password`); `DeleteFtpAccount` (`ftpid`).
- **parked_domains:** `GetParkedDomains` (`uid`); `CreateParkedDomain` (`uid`, `domain`): answers `domainid`; `DeleteParkedDomain` (`domainid`).
- **sencrypt:** `GetCertificates` (`uid`): each domain's certificate and days left; `RequestCertificate` (`uid`, `domain`: one of the account's own domains or sub-domains): a Let's Encrypt certificate now (up to a minute), used by the site within 5 minutes.
- **backupmgr:** `StartBackup` (`uid`): a backup of the account's websites and databases in the background (`started`, `running` or `recent`); `GetBackups` (`uid`): the latest backup's progress and the account's backup files. Downloads stay on the Backups page.
- **password_assistant:** `ResetUserPassword` (`uid`, `newpassword`: at least the panel's minimum length).
- **Administrators' keys only:** `sentoraconfig` `GetAllSystemOptions`; `usage_viewer` `GetServerUsageStats` (accounts, disk and this month's bandwidth in MB, mail, FTP, databases, packages, sites); `services` `GetServiceStatus`, `GetPortStatus` (`port`).

**One-time login links.** `CreateLoginLink` answers an address that logs a client into the panel without its password, for "Log in to your control panel" buttons on a reseller's own site:

- it works **once**, within **2 minutes**, for a live client account, never for an administrator;
- it opens at the reseller's own panel address when it has one (Branding), else at the main one;
- it stands for the password only: two-factor login still asks for its code, and a server that requires two-factor login still makes the client set it up;
- whoever was logged in on that browser is logged out first; a wrong or used link counts like a wrong password for the address.

**Wrong keys.** They are counted per address (not for the API as a whole, so nobody can lock your programs out): after 10 within 15 minutes, that address gets 1104 for 15 minutes, even with the right key, and each wrong key is logged for fail2ban (`/var/lib/sentora/sentora-auth.log`), which blocks an address that keeps guessing at the firewall. Requests must be POST; a module switched off in Module Admin answers none; only the requests a module's own API defines can be called.

## Passwords

| Login | Stored as | Who can see it |
| --- | --- | --- |
| Panel accounts | bcrypt (cost 12) with a per-account salt | Nobody |
| Mailboxes | `{SHA512-CRYPT}`; mailboxes moved from an old server keep `{PLAIN-MD5}` until their password is next changed | Nobody: the owner can only set a new one |
| FTP logins | SHA-512 crypt, plus an encrypted copy | The account owner, with Show password |
| MySQL users | An encrypted copy (MySQL keeps its own hash) | The account owner, with Show password |

Two-factor secrets are stored encrypted the same way. The encrypted copies use libsodium with a key derived from `cnf/security.php` (readable by root and the panel only), so a copy of the database alone is unreadable. Every Show password, and every wrong confirmation, is logged in `/var/lib/sentora/password-reveal.log`.

## Logins and sessions

- **Lockout:** 5 wrong passwords for a user name, or 20 from one address, within 15 minutes lock logins for 15 minutes (with the same answer as a wrong password). Failures are logged for fail2ban, which bans an address for an hour after 5 failures in 5 minutes on SSH, mail logins, webmail, SOGo, FTP, phpMyAdmin and the panel.
- **Two-factor login** (TOTP from an authenticator app): on per account in My Account, or required for everyone (Sentora Config). Each account gets 10 one-time recovery codes. A lost phone: use a recovery code, or ask the administrator or reseller to reset it; an administrator who lost theirs, as root: `setso --set twofactor_enabled disabled`. Resets and recovery-code logins are logged in `/var/lib/sentora/twofactor.log`.
- **Forgot password** sends a single-use link to the account's email address (at most 3 per address).
- **Remember me** keeps a login for 30 days with a random token (only its hash is stored, a new one each time it is used). When two-factor login is required and the account has not set it up, it asks for the password instead, which then asks to set it up.
- **Sessions:** a new session at every login, cookies that scripts can't read, and every form and action carries a token, so another website can't act in your name. An account that is switched off or deleted is logged out at its next page; while shadowing, the shadowing account counts.
- **Every action** checks that the item belongs to the logged-in account.

---

# Reference

## Command-line tools

All run as root. Most are in `/usr/local/sbin`; `setso`, `setzadmin` and `zppy` are in `/usr/bin`.

| Command | What it does | Changes anything? |
| --- | --- | --- |
| `sentora-post-install-check` | OK, WARN or FAIL for services, fail2ban, ports, permissions, isolation, stored passwords, TLS, updates, alerts and the daemon; report in `/var/sentora/logs/post-install-check.log` | No |
| `sentora-smoke-test [--keep]` | Uses the server like a client in 26 steps (FTPS, website, mail, SOGo, MySQL, Show password, backup and restore, isolation) with a temporary client, then removes it | Only the temporary client |
| `sentora-update` | Updates the panel (see [Updating](#updating)); `--check`, `--rollback [file]`, `--force`, `--yes` | Yes |
| `sentora-update-check` | Bundled apps behind their latest release, and Ubuntu updates waiting | No |
| `sentora-php-install <version>` | Adds an extra PHP version, or updates one the server has | Yes |
| `sentora-change-domain <name>` | Moves the panel to another host name | Yes |
| `sentora-matomo-setup [--reset]` | Sets Matomo up when it is not (a server installed before Matomo came with the panel); `sentora-update` runs it by itself | Yes |
| `sentora-restore <account> [backup.zip]` | Lists or restores an account's backups | Yes |
| `sentora-panel-restore` | Restores from the off-server backup: `--list`, `--account`, `--full` | Yes |
| `sentora-offsite-backup` | The off-server backup: `--setup` (SSH key), `--check`, `--now`, `--status` | Yes |
| `sentora-migrate-import` | Moves an account from an old Sentora server (see below) | Yes |
| `sentora-migrate-export` | Packs an old Sentora server; runs **on the old server** | No |
| `sentora-notice` | Sends, lists and withdraws panel notices | Yes |
| `sentora-web-probe <name>` | What visitors can reach: headers, cookies, files that must not be served, version leaks | No |
| `setso --show/--set <name> [value]` | Reads or changes a panel setting | Yes |
| `setzadmin --set <password>` | Sets the zadmin password | Yes |
| `zppy` | Module packages: `install`, `upgrade`, `remove`, `update`, `repo add/remove/list` | Yes |

`sentora-tls-check.sh` (on the installers release, not installed) scans every TLS service with testssl.sh and writes `findings.txt` plus a report per service.

## Moving websites from an old Sentora server

Accounts move from an old Sentora server (1.0.x or 2.0.x, on Debian, Ubuntu or CentOS) with two commands: one packs the old server, the other takes one account at a time into this one, under the same name.

1. Nothing to prepare on this server: an account that is not here yet is made from the old one (see below). To move an account under a different name, make that account first in Manage Clients.
2. On the old server, as root, get the export command. It only reads: nothing on the old server changes.

   ```bash
   wget https://github.com/RusTus/sentora-installers-2027/releases/download/current/sentora-migrate-export
   ```

   (On a very old server whose `wget` rejects GitHub's certificate, add `--no-check-certificate` and compare `sha256sum sentora-migrate-export` with the SHA-256 on the release page.)

   The export holds the panel's databases, every account's databases, folders and mail, with checksums. Choose where it goes:

   **A. Onto the old server, then copy it here** (the old server needs room for it):

   ```bash
   bash sentora-migrate-export
   ```

   It writes `/root/sentora-export-<server>-<date>/`. Copy that folder here:

   ```bash
   scp -F /dev/null -r /root/sentora-export-<server>-<date> root@<this server>:/root/
   ```

   **B. Straight onto this server over SSH** (when the old server has no room, or to skip the copy). Nothing but a small log is written on the old server; this server's root password is asked once:

   ```bash
   bash sentora-migrate-export --to-server <this server's address>
   ```

   It goes into `/root/sentora-export-<server>-<date>/` here. Use `--to <folder>` for another folder here, and `--port <n>` for another SSH port. It checks there is room here first, and the end of its output gives the import command to run here. Nothing to copy afterwards.

   This needs **root to be able to log in to this server over SSH with a password**. Ubuntu's default lets root log in only with a key (`PermitRootLogin prohibit-password`). To allow root's password while you move the accounts, run this here, as root:

   ```bash
   printf 'PermitRootLogin yes\nPasswordAuthentication yes\n' > /etc/ssh/sshd_config.d/10-sentora-move.conf && systemctl restart ssh
   ```

   When the move is done, switch it off again:

   ```bash
   rm /etc/ssh/sshd_config.d/10-sentora-move.conf && systemctl restart ssh
   ```

   When the login fails, the export shows SSH's message and what to do:

   | Message | Cause | What to do |
   | --- | --- | --- |
   | Permission denied | A wrong password, or root may not log in with a password | Check the password, or run the `printf` command above here |
   | Host key changed | This server was installed again | `ssh-keygen -R <address>` on the old server |
   | Host key verification failed | This server's key was not accepted | Run it again in a terminal and answer `yes` |
   | Connection refused | Nothing answers on that port | Check SSH runs here, or use `--port` |
   | Timed out, no route | Not reachable (address, firewall), or fail2ban banned the old server after 5 wrong passwords | `fail2ban-client set sshd unbanip <old server's address>` here |

3. On this server, see what it holds, then what moving an account would do (nothing changes yet):

   ```bash
   sentora-migrate-import /root/sentora-export-<server>-<date>
   ```

   ```bash
   sentora-migrate-import /root/sentora-export-<server>-<date> zadmin
   ```

   To move **every account** in the export in one go, use `--all` instead of an account name (with `--yes` to do it):

   ```bash
   sentora-migrate-import /root/sentora-export-<server>-<date> --all
   ```

   It checks the copy's checksums once, then moves each account in turn the same way as one by one (each shows its own plan or steps), goes on with the others when one stops, runs the daemon once at the end, and lists every account's result and the new panel logins.

4. Move it: the same command with `--yes`. It checks the copy, saves this server's panel databases in `/var/sentora/backups/pre-migrate-<date>/`, and moves the account's domains, sub-domains and parked domains, website files, DNS records (those pointing to the old server get this server's IP; `--keep-ip` leaves them), MySQL databases and users with their passwords (so the sites' settings keep working), FTP accounts, cron jobs, protected folders, mailboxes with their mail, aliases, forwarders and distribution lists, each mailbox's webmail data (address books, identities and signatures, settings) and mail filters and out-of-office replies, and the account's own FAQ entries. A name already taken here is skipped and reported; running it again adds only what is missing. Website files already here are kept (`--overwrite` replaces them). The log is `/var/sentora/logs/migrate-import.log`.
5. Point the domains' DNS to this server and open each site.

What the move takes care of:

- **The account, matched by name.** When the account is not on this server yet, it is made from the old one. Nothing is matched by the old panel's ID numbers, only by name: its owner (the old reseller, made first if it is missing too; the old zadmin is this server's administrator), its package (found by name, or made with the old package's limits) and its group (Administrators, Resellers and Users by name; a reseller's own group found by name or made with the modules that exist here, never an administrator's module for a reseller's group). It keeps its contact details (its email is left empty if another account here uses it) and gets a new panel password: panel passwords can't be carried over, because they are hashed with each server's own key. The new passwords are shown at the end and saved in `/root/sentora-migrate-passwords-<date>.txt` (readable by root only). The dry run lists what will be made, and warns when an old Administrator account would become one here. Moving the reseller's other clients later puts them under the same reseller.
- **Where everything came from.** Each account, package, group and domain the move makes or matches is recorded in the table `x_migrate_map`: the old server, what it is, its old ID and name, its new ID, and whether it was made or found here. A later run looks there first, so an account renamed here after its move is still recognised. To look one up: `SELECT * FROM sentora_core.x_migrate_map WHERE mm_old_name_vc = 'ls';`

- **PHP 7.1.** The domains get PHP 7.1, made for old sites (switched on if it is off); `--php default` gives them PHP 8.5 instead. Move each site to PHP 8 once it runs: PHP 7.1 gets no security fixes.
- **Older MySQL logins.** Sites on PHP 7.1 need `mysql_native_password`: their users get it, and the move switches it on in MySQL (`mysql_native_password = ON` in `/etc/mysql/mysql.conf.d/mysqld.cnf`; MySQL restarts once). See [PHP versions](#php-versions) for retiring them.
- **`.htaccess` lines this server refuses.** The dry run lists them; the move shows each change and asks first (`--fix-htaccess` without asking, `--no-fix-htaccess` only lists). `Options FollowSymLinks` and `All` become `SymLinksIfOwnerMatch` (links within the site and rewriting keep working), `ExecCGI` and `Includes` go, `php_value`/`php_flag` move to a `.user.ini` beside the file, and `php_admin_*` lines are commented out. Each original line stays as a comment and each file is kept as `.htaccess.before-migrate`. A site whose lines are not changed gives error 500 until they are changed by hand.
- **FollowSymLinks in a domain's own Apache settings** is switched off here; `--allow-symlinks` switches *Allow FollowSymLinks* on for exactly the domains that need it (see [Websites and Apache](#websites-and-apache)).
- **Laravel apps** (Snipe-IT and the like) get the caches made on the old server cleared (cached settings, when the app has a `.env`, and compiled templates), so they read their `.env` again here.
- **Magento and OpenCart shops** get their cache files cleared (Magento 2: `var/cache` and `var/page_cache`; Magento 1: `var/cache` and `var/full_page_cache`; OpenCart: the `cache.*` files in its cache folder), which the shop rebuilds on its next page. Magento 2's `generated/` and `pub/static` are left alone (a shop in production mode can't rebuild them by itself); a shop that keeps its cache in Redis is named, with the command to flush it.
- **Never moved:** the old panel itself: its modules (custom ones included), add-ons, settings, themes and translations; and databases or MySQL users made outside the old panel (by hand in MySQL): move those with `mysqldump` and create the user again (with `mysql_native_password` for a site on PHP 7.1).

## Logs

| Log | What is in it |
| --- | --- |
| `/var/sentora/logs/daemon-last-run.log` | The last daemon run (replaced each run) |
| `/var/sentora/logs/sentora-php.log` | PHP warnings and errors from the panel |
| `/var/sentora/logs/alerts.log` | Every server alert, and whether it was emailed |
| `/var/sentora/logs/domains/<account>/` | Each domain's access and error logs |
| `/var/sentora/logs/sentora-update.log`, `post-install-check.log`, `smoke-test.log`, `update-check.log` | The tools' reports |
| `/var/sentora/logs/migrate-import.log`, `panel-restore.log`, `offsite-backup.log` | Moves and restores |
| `/var/sentora/logs/matomo-import.log` | Hourly Matomo imports per domain |
| `/var/sentora/logs/proftpd/`, `bind/`, `roundcube/`, `php<v>-fpm.log` | FTP, DNS, webmail, extra PHP versions |
| `/var/lib/sentora/sentora-auth.log`, `phpmyadmin-auth.log` | Failed panel and phpMyAdmin logins (read by fail2ban) |
| `/var/lib/sentora/password-reveal.log`, `twofactor.log`, `shadowing.log`, `panel-update.log` | Show password, two-factor resets, shadowing, panel updates |
| `/var/log/mail.log`, `/var/log/dovecot*.log`, `/var/log/sogo/sogo.log` | Mail and groupware |

Most can be read in the panel in **System Log Viewer**; Repo Browser shows the output of each module job.

## Troubleshooting

Start with `sentora-post-install-check`: most problems show up there as a FAIL line with the fix.

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| A change in the panel hasn't happened yet | Domains, Apache, DNS, module and service changes wait for the daemon | Wait up to 5 minutes; read `daemon-last-run.log` |
| A website gives error 500 | A line in its `.htaccess` this server refuses (FollowSymLinks, `php_value`) | Its error log names the line; see [Websites and Apache](#websites-and-apache) |
| Apache Config says *Switched off: Apache refused it* | That entry has an error | Correct it and save |
| A site on PHP 7.1 can't reach its database | Its MySQL user is on the modern login method | PHP Versions > Older logins, or move the site to PHP 8 |
| Matomo shows no visits | The domain uses the tracking code without the snippet, or only bots visit | Read `matomo-import.log`; switch the domain to server logs |
| A client can't see their MySQL or FTP password | It was set before passwords were kept | Reset it once; it can be shown after that |
| A mailbox password is lost | Mailbox passwords are never shown | Set a new one in Mail > Mailboxes |
| A repository shows FAILED in Repo Browser | It can't be reached, or has no `packages.txt` | Check the address in a browser, then Retry |
| The daemon log stops part way with an SQL error | A database error ends the run | Fix it, then run `php /etc/sentora/panel/bin/daemon.php` |
| The panel's address shows *Not Found* (a different name or the IP was typed) | *Site not found falls back to panel login* is off | Use the panel's exact name, or as root: `setso --set panel_fallback true && setso --set apache_changed true && php /etc/sentora/panel/bin/daemon.php` |
| The panel says *Not Found* on the server's IP after the IP changed | The panel's IP is still the old one | Wait 5 minutes (the daemon follows it); with several local addresses: `setso --set server_ip <address> && setso --set apache_changed true && php /etc/sentora/panel/bin/daemon.php` |
| A site's Active Directory (LDAP) sign-in fails, or names ending in `.local` don't resolve on the server (osTicket: error 500, `bind() on null`) | Ubuntu's DNS helper (`systemd-resolved`, 127.0.0.53) treats `.local` as local-network names and never asks your DNS servers | Send your domain to your DNS servers, as root (your domain and DNS servers): `mkdir -p /etc/systemd/resolved.conf.d && printf '[Resolve]\nDNS=172.16.0.10 172.16.16.10\nDomains=example.local\nMulticastDNS=no\n' > /etc/systemd/resolved.conf.d/ad.conf && systemctl restart systemd-resolved`. Check with `dig +short _ldap._tcp.example.local SRV`. PHP 7.1 writes the typed password into the error log when that sign-in crashes: empty the site's `-error.log` afterwards |
| A site moved from Sentora 1.0.x says *redirected you too many times* | Sentora 1.0.x's Sencrypt kept the certificate in the domain's Extra directives on port 443; here SSL lives in its SSL settings | Nothing: the daemon takes the certificate lines out within 5 minutes. When the certificate's files are on the server they become its SSL settings; else the site runs on http until you request a certificate in Sencrypt SSL |
| `sentora-update` stops at *Downloading the core* | GitHub's download host refused for a while (it tries four times first; the message gives wget's reason) | Run it again later; [githubstatus.com](https://www.githubstatus.com) shows GitHub problems |
| A moved site shows a blank page; its log says *Access denied for user* | Its database or MySQL user was made outside the old panel, so the move did not take it | `mysqldump` it on the old server, load it here and create the user with the password the site's settings use |
| Sites slow down under load; `php<version>-fpm.log` says *server reached max_children* | The account's PHP processes are all busy | Raise **PHP processes per account** in Apache Config (each busy one uses 50 to 100 MB of memory) |
| An update went wrong | | `sentora-update --rollback` |
| Locked out by two-factor (administrator) | Lost phone and codes | `setso --set twofactor_enabled disabled` as root |

## Where things are

| Path | What |
| --- | --- |
| `/etc/sentora/panel/` | The panel's code (root's) |
| `/etc/sentora/configs/` | Service settings: Apache, Postfix, Dovecot, ProFTPD, BIND, SOGo, SSL |
| `/var/sentora/hostdata/<account>/` | Each account's files: `public_html`, `backups`, `tmp` |
| `/var/sentora/vmail/` | Mailboxes |
| `/var/sentora/logs/` | Panel, domain and tool logs |
| `/var/sentora/stats/` | GoAccess, Webalizer and Visitors reports |
| `/var/sentora/backups/` | Panel backups (updates, moves, restores) |
| `/var/lib/sentora/` | The panel's working data: sessions, queues, security logs, alert state. The folder is root's: root jobs write their state there, and the panel keeps only its own subfolders and log files (the daemon keeps it so) |
| `/opt/sentora-php/<version>/` | Extra PHP versions (services `sentora-php<version>-fpm`) |
| `/root/passwords.txt` | The passwords made at install |

Ports the services listen on (the installer sets up no firewall; fail2ban bans addresses that keep failing): 21 (FTP, TLS required, passive 60000 to 65535), 22 (SSH), 25 (SMTP), 53 (DNS), 80 and 443 (web), 465 and 587 (sending mail), 993 and 995 (IMAP and POP3 over TLS), 143 and 110 (the same with STARTTLS; no password without encryption), 4190 (ManageSieve). MySQL, memcached and SOGo listen on this server only.

## Releases

Both repositories have one release with the tag **`current`**; new installs, `sentora-update` and `sentora-php-install` take their files from it. A new version replaces the files there.

| Release | Files |
| --- | --- |
| [sentora-core-2027](https://github.com/RusTus/sentora-core-2027/releases) | `sentora-core-2027.zip` (the panel) |
| [sentora-installers-2027](https://github.com/RusTus/sentora-installers-2027/releases) | `sentora2027-ub26.04`, `sentora2027-deb13` and `sentora2027-alma10` (the installers), `sentora-installers-2027.zip`, `sentora-php-<version>-<ubuntu26.04, debian13 or alma10>-amd64.tar.gz`, `sentora-migrate-export`, `sentora-smoke-test`, `sentora-web-probe`, `sentora-tls-check.sh`, `testssl_sh-3_2_5.zip` |

**Making a release.** Push to `master2027`, add the changes to the change log as usual (one file, written as always), run `php .github/scripts/stamp-rustus.php` in the core (it keeps the *Updated by RusTus* / *Written by RusTus* line and its date in the header of every PHP file that differs from Sentora 2.0.2), bump `VERSION` and add a `- Release <version>:` line to the change log, then in each repository open **Actions > Release > Run workflow**. The workflow:

1. builds the files from `master2027` (the zips with `git archive`, the scripts from their only copy);
2. uploads a file to `current` only when its content changed, so a run with nothing new makes no update for servers (*force* uploads anyway), and prints each file's SHA-256;
3. works out **What's new**: every line added to the change log since the previous version (since the commit that added its `- Release <previous>:` line);
4. rewrites the `current` release page: the title `Sentora-<VERSION>`, What's new, and this README;
5. makes a release for the version, once (tag `v<VERSION>`), with What's new, the whole `CHANGELOG.md` and a copy of every file of `current`. The Releases page so lists every version with what changed in it and its files; later runs of the same version replace the files they changed there too.

The README and the change log are not in the core zip, so editing them never makes an update for servers. The PHP bundles and the testssl zip are replaced by hand on the installers release (a version release made after that copies them). Upload them to `current` itself: the release titled *Sentora-<version> (current)*, marked **Latest** at the top of the Releases page (or https://github.com/RusTus/sentora-installers-2027/releases/tag/current), then Edit. The version releases (*Sentora-<version>*, tag `v<version>`) are copies.

## Repository layout

| Path | Contents |
| --- | --- |
| `bin/` | The daemon, `sentora-update` and the other command-line tools, `zsudo.c` |
| `cnf/` | Templates for the database settings and encryption key (the installer fills them in) |
| `dryden/` | The panel's framework classes (`ctrl_` accounts and security, `sys_` system work, `ui_` pages) |
| `etc/apps/` | Bundled web apps: phpMyAdmin, Roundcube, Matomo |
| `etc/styles/` | Themes |
| `etc/lang/` | Translations |
| `inc/` | Start-up code |
| `modules/` | Panel modules, one folder each (`code/`, `hooks/` run by the daemon, `module.zpm` page) |

## License and credits

Sentora-2027 is licensed under the [GNU General Public License v3](LICENSE.md). To report a vulnerability, see the [security policy](SECURITY.md); to contribute, see [contributing](.github/CONTRIBUTING.md); questions and problems go to [issues](https://github.com/RusTus/sentora-core-2027/issues).

It is based on [Sentora](https://github.com/sentora/sentora-core) by the Sentora team, itself a fork of ZPanelCP. Bundled applications keep their own licenses, listed in their folders.
