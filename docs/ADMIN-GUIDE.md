# Sentora-2027 for Ubuntu 26.04: Administrator's Guide

Nov 5, 2026 · @Russell SKinner

## Overview

Sentora-2027 runs on Ubuntu 26.04 only, with every hosting account isolated as its own Linux user and every stored password hashed or encrypted. It is installed and updated from the GitHub releases of [RusTus/sentora-core-2027](https://github.com/RusTus/sentora-core-2027/releases) and [RusTus/sentora-installers-2027](https://github.com/RusTus/sentora-installers-2027/releases).

| Part | Version | How it is updated |
| --- | --- | --- |
| Apache (event engine, HTTP/2) | 2.4.66 | Ubuntu security updates |
| PHP for the panel and sites | 8.5 | Ubuntu security updates |
| Extra PHP versions per domain | 8.2, 8.3, 8.4 | the Sentora PHP tarballs |
| MySQL | 8.4 | Ubuntu security updates |
| Postfix, Dovecot, ProFTPD, BIND | 3.10, 2.4, 1.3.9, 9.20 | Ubuntu security updates |
| SOGo groupware with ActiveSync | 5.12 | Ubuntu security updates |
| phpMyAdmin, Roundcube, Matomo | 5.2.3, 1.7.5, 5.14.2 | `sentora-update` (bundled in the core) |
| Tiny File Manager, Pimped Apache Status, GoAccess | 2.6, 2.04.17, 1.12 | `sentora-update` (bundled in the core) |
| PHP-Adminer, Webalizer Stats, Visitor Stats | 6.3.0, 2.23, 0.7 | the add-on repository |

The files come from the release tagged current in each repository (a new version replaces its files), and add-on modules from a folder of the installers repository:

- [sentora-core-2027 releases](https://github.com/RusTus/sentora-core-2027/releases): `sentora-core-2027.zip`. [sentora-installers-2027 releases](https://github.com/RusTus/sentora-installers-2027/releases): `sentora2027-ub26.04` (the installer), `sentora-installers-2027.zip`, the PHP tarballs, `sentora-tls-check.sh`, `sentora-web-probe` and `sentora-smoke-test`. GitHub lists each file's SHA-256; the installer and `sentora-update` check every download against it.
- The [`sentora-repo` folder](https://github.com/RusTus/sentora-installers-2027/tree/master2027/sentora-repo) of sentora-installers-2027: the add-on modules, with `packages.txt` and `SHA256SUMS`, read by Repo Browser from `https://raw.githubusercontent.com/RusTus/sentora-installers-2027/master2027/sentora-repo`.

The CHANGELOG lists every change in detail; this guide covers how to run the server.

## Installing

A fresh install takes about 30 minutes on a clean Ubuntu 26.04 server and ends with a restart.

Before you start:

- A clean Ubuntu 26.04 server (x86\_64): no other control panel, web or mail server installed.
- A fully qualified host name for the panel, for example `panel.example.com`, with an A record pointing at the server. The installer warns if the name does not resolve.
- Access from the server to github.com and api.github.com (see Overview).

As root:

```bash
wget https://github.com/RusTus/sentora-installers-2027/releases/download/current/sentora2027-ub26.04
bash sentora2027-ub26.04
```

The installer asks for the panel name, the time zone and the public IP address, installs and configures everything, and prints the passwords at the end. They are also saved in `/root/passwords.txt` (readable by root only). The full log is left in `/root/`.

Every login lands on the **Dashboard** (Account Information > Dashboard), with Continue to your panel administration to reach the usual home page. Each account arranges its own widgets: Getting started and Account usage by default, and Your domains, Security, Backups and, for administrators, Server status to add. Switching the Dashboard off in Module Admin sends logins to the home page as before.

**Dashboard widgets.** Admin > Server Admin > Widget Admin decides, for each widget, whether it is on, whether new dashboards include it and in what order, and which groups may use it; Apply the defaults to every account now resets every dashboard. New widgets are uploaded there as a .zip with `widget.xml`, `widget.php` (a class `dashboard_widget_<id>` with `Render($user)`) and an optional `widget.css`; `etc/dashboard-widgets/README.md` describes the format, and `widget-quick_links.zip` is an example. The daemon checks each package and installs it within 5 minutes. A widget's code runs inside the panel, so upload only widgets you trust.

**Spam and virus filtering (SpamAV).** SpamAssassin and ClamAV check every incoming message while the sender is connected: viruses and very high spam scores are refused, other spam is marked and filed into the mailbox's Spam folder (or left in the inbox, as each mailbox's owner chooses). If a scanner is down, mail is still accepted. Admin > Server Admin > SpamAV Admin shows the scanners' status and signature dates, updates them on request, and holds every setting for both, with limits and explanations. Mail > SpamAV shows each account what was caught for its mailboxes and lets it set, per mailbox, the spam filter, its sensitivity, where spam goes and allowed and blocked senders (unless you turn that off). ClamAV needs about 1.5 GB of memory and is left off on smaller servers; switch it on in SpamAV Admin when there is room.

After the restart, wait 5 minutes for the first daemon run, then:

1. Run `sentora-post-install-check` and fix any FAIL line (see The command-line tools).
2. Log in at `https://<panel name>` as `zadmin` and change the password under Account > My Account.
3. Set an alert address under Admin > Sentora Config > Alert email address.
4. Install PHP-Adminer, Webalizer Stats or Visitor Stats from Repo Browser if you want them (see Add-on modules).
5. Once the panel name is in public DNS, issue a Let's Encrypt certificate with Sencrypt (see Certificates).
6. Run `bash sentora-tls-check.sh <panel name>` to confirm the TLS settings.

## The command-line tools

These scripts do the jobs that are easy to get wrong by hand; all run as root, and all but the TLS check are on the path in `/usr/local/sbin`.

| Command | What it does | Changes anything? |
| --- | --- | --- |
| `sentora-post-install-check` | Prints OK, WARN or FAIL for services, fail2ban jails, listening ports, file permissions, account isolation, stored passwords, TLS, automatic updates, alerts and the daemon. Report saved in `/var/sentora/logs/post-install-check.log`. | No |
| `sentora-update` | Updates the panel from the current GitHub release of sentora-core-2027, with a backup first. `--check` says whether there is an update; `--rollback` restores a backup. | Yes |
| `sentora-update-check` | Lists bundled apps that are behind their latest release on GitHub, and the Ubuntu updates waiting. Report saved in `/var/sentora/logs/update-check.log`. | No |
| `sentora-restore <account> <backup.zip>` | Restores an account's web files and databases from one of its backups, after backing up its current state. Without a file it lists the account's backups. | Yes |
| `bash sentora-tls-check.sh <name> [quick]` | Scans every TLS service with testssl.sh and writes `findings.txt` plus a full report per service. Downloaded from the sentora-installers-2027 release, not installed. | No |

Run `sentora-post-install-check` after installing, after every update and whenever something seems wrong; its FAIL lines say what to do. On a new server expect a few WARNs: the self-signed certificate, isolation not yet testable with only one hosting account, and extra PHP versions that are not switched on.

**The smoke test.** `sentora-smoke-test` uses the server the way a client does, in about 2 minutes. It makes a temporary client with a domain, an FTP account, a mailbox, a database and a MySQL user through the panel's own pages. It then uploads a page by FTPS, loads it, sends and reads mail, logs in to SOGo and MySQL, checks Show password, backs up and restores the account, checks isolation, and removes everything. Each step prints PASS, FAIL or SKIP with a reason; a step that needs a failed one is skipped and says which. The last lines say `RESULT: PASSED` or `RESULT: FAILED` with the failed steps again, and the exit code is 0 only when everything passed. The report is saved in `/var/sentora/logs/smoke-test.log`; `--keep` leaves the test client in place to look at. Run it after `sentora-post-install-check`, and after every update.

## Keeping it up to date

Ubuntu installs its own security updates every day; the panel and its bundled apps are updated with `sentora-update` when a new release is published on GitHub.

**Ubuntu packages.** unattended-upgrades installs security updates daily (Apache, PHP, MySQL, Postfix, Dovecot, SOGo and the rest). It never restarts the server: when an update needs a restart, `sentora-post-install-check` warns, and you restart at a time you choose. Other updates: `apt-get upgrade` by hand.

**The panel.** After new files are put on the `current` release of sentora-core-2027:

```
sentora-update --check     # is there a newer core?
sentora-update             # back up, update, run the daemon and the post-install check
```

It never touches this server's own files: the database settings and key in `cnf/`, `zsudo`, the template cache, Matomo's config, the repository lists and installed add-on modules. The last 5 backups are kept in `/var/sentora/backups/`. To undo an update:

```
sentora-update --rollback                 # lists the backups
sentora-update --rollback /var/sentora/backups/panel-before-update-<date>.tar.gz
```

New core modules appear for administrators only; give them to other groups in Admin > Module Admin.

**Bundled apps.** phpMyAdmin, Roundcube, Matomo, Tiny File Manager, Pimped Apache Status and GoAccess ship inside the core zip, so they are updated when a new core is built and installed with `sentora-update`. Run `sentora-update-check` now and then to see which have a newer release.

**Service configurations.** `sentora-update` updates the panel only. Changes to Postfix, Dovecot, ProFTPD, Apache or fail2ban settings are listed in the CHANGELOG with the commands to apply them, or come with a fresh install.

**PHP versions.** Each PHP version gets security fixes until its end date: 8.2 until 2026-12-31, 8.3 until 2027-12-31, 8.4 until 2028-12-31, 8.5 until 2029-12-31. The domain lists mark a version that has ended (red) or ends within 90 days (yellow), and the daemon's daily run lists the domains concerned. New installs no longer get 8.1.

## Add-on modules and the repository

Optional modules come as `.zpp` packages from the Sentora-2027 add-on repository on GitHub (`https://raw.githubusercontent.com/RusTus/sentora-installers-2027/master2027/sentora-repo`), listed in Repo Browser by default and installed by the daemon within 5 minutes of being queued. Each download is checked against the repository's `SHA256SUMS`; a module that doesn't match isn't installed.

| Package | What it adds | Who can use it |
| --- | --- | --- |
| `PHP-Adminer` | Adminer 6.3.0 for an account's own MySQL databases, styled like the panel | Every group; each account logs in with its own MySQL users only |
| `webalizer_stats` | Webalizer traffic reports per domain, built hourly | Every group; each account sees its own domains |
| `visitor_stats` | Visitors reports per domain: visitors per day, pages, referrers, browsers | Every group; each account sees its own domains |

To install from the repository:

1. Admin > Server Admin > Repo Browser: the add-on repository is already listed. To add another, type its address: `https://host/path` for HTTPS, or `host/path` for plain http on your own network. It shows QUEUED until the daemon has added it, then OK, or FAILED with a Retry button.
2. Click Install next to a package. The button shows Queued until the daemon has installed it (up to 5 minutes).

To install a package file instead: Admin > Module Admin, upload the `.zpp`. The file name, without `.zpp`, becomes the module's folder name.

Webalizer Stats and Visitor Stats build their programs on install. Removing them keeps the programs and the reports, so a reinstall carries on where it stopped.

To add your own package to the repository: copy `<Name>.zpp` into the `sentora-repo/` folder of sentora-installers-2027, add `<Name>` on its own line in `packages.txt`, run `sha256sum *.zpp > SHA256SUMS`, and commit to `master2027`. GitHub can take a few minutes to serve the new files. Installing a module runs its code inside the panel, so add only modules you trust.

## How the server is protected

A break-in to one website, or to the panel, is contained: each account runs as its own user, the panel cannot change its own code, and no password is stored in a readable form.

**Hosting accounts.** Each account has its own Linux user, `web<account id>`, and its own PHP-FPM pool per PHP version it uses. Its folder in `/var/sentora/hostdata/` belongs to that user and is closed to everyone else; access lists give the panel read-write and Apache read-only access. FTP logins and cron jobs run as the account's user. Every hour the daemon gives stray files back to the account's user and closes the folders again.

**The panel.** The panel's PHP runs as the user `sentora` in its own pool. The panel's code belongs to root, so the panel cannot change it; module installs and other root jobs are queued by the panel and run by the root daemon. The database settings and key (`cnf/db.php`, `cnf/security.php`) are readable by root and the panel only.

**What a site's .htaccess can do.** Sites may use their own `.htaccess` for rewrites, redirects, headers, caching and password protection, but may hand requests only to their own account's PHP. A `SetHandler` or `RewriteRule [H=...]` pointing at the panel's PHP pool, another account's pool, `server-status` or a proxy is refused (403) or neutralised, in three layers: `sentora-guard.lua` (mod\_lua) refuses such handlers; a `<Location>` block in each hosted vhost, which `.htaccess` can not override, gives PHP-FPM no script and no settings for any socket but the account's own, and blanks status pages; and the panel's pool runs only the panel's own scripts. Refusals are logged in the domain's error log.

**Log analysers.** Webalizer, Visitors and GoAccess run as the user `sentora-stats`, which can read nothing else; the root job hands them the log lines.

**Protected folders.** Advanced > Protected Directories adds a username and password to a folder of an account's website (Apache basic authentication). The folder is chosen in a tree of the account's own `public_html`. The panel writes a marked block into that folder's `.htaccess` and keeps the passwords (bcrypt) in the account's `htpasswd/` folder, outside the website; removing the protection takes the block out again and leaves the folder's own `.htaccess` lines as they were. The panel never writes through a symbolic link, so an account cannot make it change another account's files.

**Stored passwords.**

| Login | Stored as | Who can see it |
| --- | --- | --- |
| Panel users | Salted hash | Nobody |
| Mailboxes | `{SHA512-CRYPT}` (old ones `{PLAIN-MD5}` until changed) | Nobody: the account owner can only set a new one |
| FTP accounts | SHA-512 crypt for logins, plus an encrypted copy | The account owner, with Show password |
| MySQL users | Encrypted copy (MySQL keeps its own hash) | The account owner, with Show password |

Encrypted copies use libsodium with a key derived from `cnf/security.php`, so a database copy alone is unreadable. Each Show is logged in `/var/lib/sentora/password-reveal.log`; Admin > Sentora Config > Confirm panel password to show passwords makes it ask for the panel password first.

**Network.** TLS 1.2 and 1.3 only, with forward-secrecy ciphers (Mozilla intermediate) on HTTPS, mail and FTP. MySQL, memcached and SOGo listen on this server only. fail2ban bans an address for 1 hour after 5 failures in 5 minutes on SSH, SMTP login, IMAP and POP3, webmail, SOGo, FTP, phpMyAdmin and the panel. Panel logins can add a second step (TOTP) under Account > My Account once enabled in Sentora Config.

**Getting back in.** A forgotten password is reset with the Forgot password link on the login page (a single-use link to the account's email address); two-factor login still applies after it. For a lost phone, each account that uses two-factor login has 10 one-time recovery codes, shown once when it is turned on; each can be used once at login instead of the app's code ("Use a recovery code"), and My Account shows how many are left and makes a new set. A client who lost both phone and codes asks their administrator or reseller, who checks who is asking and presses Reset two-factor on that client in Manage Clients. Recovery-code logins and resets are logged in `/var/lib/sentora/twofactor.log`.

**Restoring from the full backup.** Admin > Backup Config > Restore reads the snapshots on the backup machine (Read the backups), from any server's name, so a newly installed server can restore an old one: set up the off-server backup on the new server with the same backup machine and folder first. Open a snapshot to see its accounts. Restore one account (websites, databases and MySQL users, mailboxes; the account must exist with the same username) or the whole panel (type RESTORE to confirm; best on a fresh install). What is there before is always saved first in /var/sentora/backups/pre-restore-\*, and the log is /var/sentora/logs/panel-restore.log. From the command line: sentora-panel-restore --list, --account, --full.

## Email

Each mailbox has its own password, separate from any panel login; mailbox users change it themselves in webmail, and the account owner can only set a new one.

| Service | Address | Notes |
| --- | --- | --- |
| Roundcube webmail | `https://<panel name>/etc/apps/webmail/` | Settings > Password changes that mailbox's password only |
| SOGo (mail, calendar, contacts) | `https://<panel name>/SOGo` | CalDAV and CardDAV for calendar and contact apps |
| ActiveSync (phones, Outlook) | `https://<panel name>/Microsoft-Server-ActiveSync` | On or off in Admin > Mail Config |
| IMAP / POP3 | ports 993 / 995 (TLS) |  |
| Sending (SMTP) | port 587 (STARTTLS) or 465 (TLS) | Login required |

Mailbox passwords:

- Changing a mailbox password in webmail changes that mailbox only, never a panel user's password.
- An account owner or reseller cannot see a mailbox password, only set a new one under Mail > Mailboxes > Edit.
- New and changed passwords are stored as SHA-512 crypt. Mailboxes made before this build keep their old MD5 password until it is next changed; `sentora-post-install-check` shows how many are left.

SOGo's settings (language, time zone, message size, filters, out-of-office, forwarding, reminders, ActiveSync) are in Admin > Mail Config; the daemon applies them within 5 minutes and restarts SOGo when needed.

## Statistics

All statistics are built from the web server logs every hour, so new visits appear within the hour; each account sees only its own domains.

| Module | Where | What it shows |
| --- | --- | --- |
| GoAccess | Advanced > GoAccess | Traffic reports per domain, Bright theme, a year of history. Administrators also see the panel and all domains together. |
| Matomo | Advanced > Matomo | Full analytics per domain, opened without a second login |
| Webalizer Stats (add-on) | Advanced > Webalizer Stats | Classic Webalizer reports per domain |
| Visitor Stats (add-on) | Advanced > Visitor Stats | Visitors per day, pages, referrers, browsers |
| Server Monitor | Admin > Server Admin > Server Monitor | The server itself, live: CPU, memory, disks, network, processes, services |

**Matomo** gets its visits one of two ways, chosen per domain on the Matomo page:

- Use server logs (the default): the hourly job imports the new log lines. Bots and static files (images, CSS) are left out, so a site visited only by bots and monitors shows no visits.
- Use tracking code: visits come only from the Matomo snippet on the site's pages, never from the logs.

Each hourly import writes a line per domain to `/var/sentora/logs/matomo-import.log`: lines read, visits recorded, lines left out, failures, and domains using the tracking code. Look there first when Matomo shows nothing.

## Monitoring and alerts

The daemon checks the server every 5 minutes and emails the administrator when a disk is getting full, a core service has stopped, or the outgoing mail queue grows.

| Check | Alert when |
| --- | --- |
| Disk space | a file system is fuller than Alert disk use percent (85% by default) |
| Services | Apache, PHP, MySQL, Postfix, Dovecot, ProFTPD, BIND, fail2ban, cron, SOGo or memcached is not running |
| Mail queue | more than 100 messages are waiting (often a site sending spam: run `mailq`) |

One email goes out when a problem starts, a reminder every 24 hours while it lasts, and one when it is resolved. Settings are in Admin > Sentora Config:

- Alert email address: where alerts go. Empty means the zadmin account's email address.
- Alert disk use percent: 50 to 99.

Every alert is also written to `/var/sentora/logs/alerts.log`, also when sending the email failed. Server Monitor (Admin > Server Admin) shows the same things live.

## Backups and restoring

A backup is a zip of an account's `public_html` plus a dump of each of its MySQL databases, kept in the account's own `backups/` folder; `sentora-restore` puts one back.

**Making backups.** Account owners make them in Advanced > Backup with Back up now: the backup is made in the background while the page shows its progress, and appears in the list with a Download button when it is ready. The daily job (Admin > Backup Config) makes them for every account. Backups belong to the account's own user, so its FTP login can download them. They hold web files and databases, not mailboxes or settings.

**Restoring an account**, as root:

```
sentora-restore <account>                 # lists its backups, newest first
sentora-restore <account> <backup.zip>    # web files and databases
```

Options: `--files` (web files only), `--databases` (databases only), `--exact` (also remove files that are not in the backup), `--yes` (do not ask).

Before changing anything it backs up the account as it is now, and prints the command to undo the restore. It accepts only files under `public_html/` and dumps of the account's own databases, so a backup cannot write elsewhere. Restored files belong to the account's user, and a database restore replaces the tables that are in the backup.

**Off the server.** Admin > Backup Config > Off-server backup copies everything needed to rebuild the server to another machine every night: every account's backups, the panel backups, a dump of all MySQL databases made at that moment (the panel's own included), the configuration and, if ticked, the mailboxes. Each night is a dated snapshot on the backup machine in which unchanged files are hard links to the night before, so only changes are sent and stored.

| Setting | What it does |
| --- | --- |
| Enabled | Off: nothing is copied; the settings are kept |
| Server, Port | The backup machine, over SSH (port 22 by default). Empty server: the folder is on this server, for example an NFS mount |
| Username, Password | The login on the backup machine. The password is stored encrypted and never shown again; without one, the SSH key made by `sentora-offsite-backup --setup` is used (more secure) |
| Folder | Where the copies go; each server gets its own folder in it, one per night |
| Time | Every day at this time (server time) |
| Nights kept | Older nights are removed from the backup machine |
| Mailboxes | Include the mailboxes, copied incrementally |

Test connection and Back up now are run by the daemon within 5 minutes; the page shows the last test, the last good copy and the last error. The copy holds the database settings and keys, so the backup machine must be one you trust. The server alerts email when it is switched on and has not succeeded for 26 hours.

**Clients' own FTP backups.** On Advanced > Backup, each client can have a backup of their websites and databases (not their mail) uploaded every day to their own FTP server, at the hour they choose, keeping the number of copies they choose. FTPS with TLS is the default; the password is stored encrypted. Admin > Backup Config has two switches: Client FTP backups (on) lets clients use it at all, and Client FTP private networks (off) decides whether they may upload to private network addresses, such as a server on your own network. Loopback and link-local addresses are always refused, and on this server's own addresses only the FTP ports are allowed, so the option can not be used to reach the server's own services.

## Certificates

The installer makes a self-signed certificate valid for 10 years; replace it with a Let's Encrypt certificate as soon as the panel name is in public DNS.

Until then, browsers and mail clients warn on first connection, and `sentora-tls-check.sh` grades every service T (trust) whatever its settings. The encryption itself is as strong either way.

To switch to Let's Encrypt:

1. Make sure the panel name resolves to the server's public address from the internet, and that port 80 is reachable.
2. In the panel, use Sencrypt to issue a certificate for the panel name, and for each hosted domain that needs one.
3. The daemon uses the panel's certificate for mail (Postfix, Dovecot), FTP and SOGo too, and Sencrypt renews it automatically.
4. Run `bash sentora-tls-check.sh <panel name>` again: the T grades become A or A+.

Optional, once the name is public: a DNS CAA record that allows only Let's Encrypt to issue certificates for it.

## Logs

Most logs can be read in the panel under Admin > Server Admin > System Log Viewer; the table says which to open for what.

| Log | What is in it |
| --- | --- |
| `/var/sentora/logs/daemon-last-run.log` | The last daemon run (every 5 minutes; hourly and daily parts when due). Replaced each run |
| `/var/sentora/logs/sentora-php.log` | PHP warnings and errors from the panel |
| `/var/sentora/logs/alerts.log` | Every server alert, and whether it was emailed |
| `/var/sentora/logs/matomo-import.log` | Hourly Matomo imports per domain |
| `/var/sentora/logs/post-install-check.log` | The last `sentora-post-install-check` report |
| `/var/sentora/logs/update-check.log` | The last `sentora-update-check` report |
| `/var/sentora/logs/domains/<account>/` | Each domain's access and error logs |
| `/var/lib/sentora/sentora-auth.log` | Failed panel logins (fail2ban reads it) |
| `/var/lib/sentora/phpmyadmin-auth.log` | Failed phpMyAdmin logins (fail2ban reads it) |
| `/var/lib/sentora/password-reveal.log` | Every Show password, with who and from where |
| `/var/log/unattended-upgrades/` | Automatic Ubuntu updates |
| `/var/log/sogo/sogo.log`, `/var/log/mail.log` | SOGo, and Postfix and Dovecot |

Repo Browser's Recent ZPPY commands shows the full output of each module or repository job.

## Troubleshooting

Start with `sentora-post-install-check`: most problems below show up there as a FAIL line with the fix.

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| A panel change has not happened yet | Module, repository, cron and service changes wait for the daemon | Wait up to 5 minutes; check `daemon-last-run.log` |
| Matomo shows no visits | The domain uses the tracking code without the snippet, or only bots visit | Read `matomo-import.log`; switch the domain to Use server logs |
| Matomo import FAILED: name resolution | An older panel version could not resolve its own name | `sentora-update` (the import now connects locally) |
| A client cannot see their MySQL or FTP password | It was set before passwords were kept | Reset it once; it can be shown after that |
| A mailbox password is lost | Mailbox passwords are never shown | Set a new one in Mail > Mailboxes > Edit |
| FTP login fails after an update | ProFTPD accepts hashes only | Reset the FTP password in the panel |
| Repository shows FAILED | The repository cannot be reached, or `packages.txt` is missing | Check the address in a browser, then Retry |
| Daemon log stops part way with SQL Error | A database error ends the whole daemon run | Read the error, fix it, then run `php /etc/sentora/panel/bin/daemon.php` |
| A domain is marked "no security fixes" | Its PHP version has ended | Choose a newer PHP version for it in Domains |
| An update went wrong |  | `sentora-update --rollback` |

To run the daemon by hand, including its hourly part: `setso --set daemon_hourrun 0 && php /etc/sentora/panel/bin/daemon.php`.

## Reference

Settings added in this build, in Admin > Sentora Config:

| Setting | Default | Effect |
| --- | --- | --- |
| Alert email address | empty (zadmin's address) | Where server alerts go |
| Alert disk use percent | 85 | Disk alert threshold |
| Confirm panel password to show passwords | false | Show password asks for the panel password first |
| Two-factor login | false | disabled, enabled (the default on new installs: each account can turn on a code from an authenticator app in My Account) or enabled-forced (every account must use it: one that has not set it up does so right after its password, and it can not be turned off). Lost phone: setso --set twofactor\_enabled disabled |
| Serve panel on server IP | true (on) | The panel also answers on the server's own IP address (https://\<ip>), not only its name; any other unknown name still gets 404. Turn it off on a production or internet-facing server so the login is not shown to anything that scans the IP. setso --set panel\_ip\_access false |

Command and path settings (`zsudo`, `httpd_exe`, `hosted_dir`, `log_dir`, `ftp_php`, `mailserver_php` and others) are locked in the panel; change them as root with `setso --set <name> <value>`.

Where things are:

| Path | What |
| --- | --- |
| `/etc/sentora/panel/` | The panel's code (root's) |
| `/etc/sentora/configs/` | Service configurations: Postfix, Dovecot, ProFTPD, Apache, BIND, SOGo, Roundcube, phpMyAdmin, SSL |
| `/var/sentora/hostdata/<account>/` | Each account's files, `public_html` and `backups` |
| `/var/sentora/logs/` | Panel and domain logs |
| `/var/sentora/stats/` | GoAccess, Webalizer and Visitors reports |
| `/var/sentora/backups/` | Panel backups made by `sentora-update` |
| `/var/lib/sentora/` | The panel's working data: sessions, uploads, auth logs, alert state |
| `/opt/sentora-php/`, `/opt/sentora-goaccess/` | Extra PHP versions, GoAccess |
| `/root/passwords.txt` | The passwords made at install |

Ports open to the network: 21 (FTP, TLS required), 22 (SSH), 25 (SMTP), 53 (DNS), 80 and 443 (web), 465 and 587 (mail sending), 993 and 995 (IMAP and POP3 over TLS). MySQL (3306, 33060), memcached (11211) and SOGo (20000) listen on this server only.
