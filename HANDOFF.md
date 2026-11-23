# Where things are (the long notes)

The project notes for the part you are working on. CLAUDE.md has the rules and is read first; this file has the details
(what was built, how it works, what was tested) and is read for the part you touch. Find your part by its heading.
Keep it up to date: when you change something, change its section here (not CLAUDE.md), and put the change itself in
CHANGELOG.md of both repositories. Kept in sentora-core-2027 only (the installers' CLAUDE.md points here).

Read this first in a new session; the CHANGELOG has every change in detail.

## Supported systems (each has its installer on the `current` release)

Ubuntu 26.04
(`sentora2027-ub26.04`), Debian 13 (`sentora2027-deb13`, MariaDB 11.8, PHP 8.4), AlmaLinux 10 and
Rocky 10 and Red Hat Enterprise Linux 10 (`sentora2027-alma10`, MariaDB 10.11, PHP 8.3, Dovecot 2.3 with `preconf/dovecot2-23`,
SELinux enforcing, SOGo from Sentora's own build). `sentora2027-install` (on the release) picks the right
installer for the system it runs on; Fedora 45 has `sentora2027-fedora`, and Windows 11 / Server 2025 the WSL script and
Sentora2027Setup.exe (see below). What differs between systems lives in hidden x_settings read through
`dryden/sys/platform.class.php` (`sys_platform::Get`, shell tools: `setso --get <name>`); each
installer sets its values in its "Configuring Sentora" step. Keep the core free of distribution
names (CLAUDE.md, standing rules).

## Tested

Debian 13 on the owner's VM (post-install check 0 FAIL, smoke test 26/26). AlmaLinux 10.2
on the owner's VM with SELinux enforcing (release 2027.65: post-install check 81 OK / 0 FAIL, smoke
test 25/26 with SOGo skipped, no SELinux denials since boot); again on 2026-11-10 with release 2027.69 and
SOGo from Sentora's build: post-install check 87 OK / 0 FAIL, smoke test 26/26. Debian VM: 2027.69, smoke 26/26.
The owner's network alters plain-http downloads (AlmaLinux mirrors' repomd.xml arrived garbled): the dnf installers
now switch to the distribution's HTTPS server when the package lists can not be read. Debian and AlmaLinux also in Docker
containers with systemd.

## SELinux and AppArmor

**SELinux:** labels are set in the AlmaLinux installer's SELinux step
(semanage fcontext, restorecon, booleans); what labels can not fix goes in
`preconf/selinux/sentora.cil` (installers repo, loaded with `semodule -i`; one rule so far: BIND may
enter /var/sentora/logs). Some refusals are not logged (dontaudit): when a service fails with
"permission denied" and `ausearch` shows nothing, check its rules with `sesearch` (an AlmaLinux 10
container with selinux-policy-targeted, -targeted-extra from CRB, and setools-console). rsync started by a service or cron enters rsync_t (an rsync server's domain: no ssh, no reading the
sites): Sentora's root tools run it with `runcon "$(id -Z)" rsync` (RSYNC in bin/sentora-backup-common), not with
wider rules; the VM test's x_offsite checks it. Never
switch SELinux off ("less secure, not done"). **AppArmor (Debian, Ubuntu, since 2026-11-10):** on, never switched off. Sentora's paths for a
program go in `preconf/apparmor/local/<profile>` (installers repo), which the installers' `aa_sentora` writes into
/etc/apparmor.d/local/<profile> between `# BEGIN Sentora` / `# END Sentora` and reloads. So far: BIND (usr.sbin.named)
and ProFTPD (proftpd, Ubuntu only). MySQL and ClamAV need nothing. Find what a program needs with its profile in
complain mode (`aa-complain`, reboot, use it, then `journalctl -k -b | grep apparmor=`). Released in 2027.71 and tested with
fresh installs on the owner's VMs (2026-11-11): Ubuntu 26.04 100 OK / 0 FAIL, smoke 26/26 (BIND, MySQL, ProFTPD,
ClamAV confined); Debian 13 96 OK / 0 FAIL, smoke 26/26 (BIND, ClamAV confined); nothing refused. (Ubuntu's own
systemd-detect-virt profile logs refusals at boot: the system's, not Sentora's; the check counts only Sentora's services.) Servers installed before
switch it on with `sentora-apparmor on` (since 2027.78; the post-install check warns until then).

## PHP bundles

built on GitHub by Actions > PHP bundles (system ubuntu26.04, debian13, alma10 or fedora45;
versions empty = usual set, `8.4` = newest from php.net, or exact; `list_only` rewrites the list).
Each run writes `php-bundles.json` on the release (system, version, SHA-256 of every build). The
panel checks it daily (`dryden/sys/phpbundles.class.php`): a notice and an Update button in PHP
Versions when a newer build is ready; nothing is ever compiled on a server (owner's decision). While php.net has a release whose Sentora build is not published yet ("not ready yet"), or the list could not be read, the daemon checks hourly instead of daily (2026-11-20: two panels showed different notices for hours while the builds ran one system after another).

## Install tests (since 2027.68)

both release workflows first run `install-test.yml` (installers
repo): Ubuntu 26.04, Debian 13, AlmaLinux 10, Rocky Linux 10 and Fedora 45 are installed from master2027 of both repositories in
containers with systemd, restarted, and must pass sentora-post-install-check (no FAIL) and
sentora-smoke-test; nothing is published otherwise (`skip_tests` only for a broken test system). The
script is `.github/scripts/install-test.sh` (also runs on a test machine: TEST_CA, CGROUP_DIR, TEST_SOGO,
TEST_KEEP). Actions > Install test runs it alone; each run keeps its results (logs, checks) 14 days.
On the runner the host's own AppArmor profiles are unloaded; for Debian and Ubuntu the test then loads the
container's own profiles into the runner's kernel (they confine the same programs inside the container) and fails
on any refusal. The workflow takes `installers_ref` and `core_ref`: test a work branch before it goes to master2027.

## VM test (2026-11-12, installers repo: Actions > VM test, `.github/scripts/vm-test.sh` + `vm-exercise.sh`)

real KVM machines on GitHub from each system's cloud image, SELinux enforcing (AlmaLinux 10) or AppArmor on (Debian 13, Ubuntu 26.04): install, restart, check, smoke test, then `vm-exercise.sh` uses everything (every panel page, root buttons, mail 25/587/465, IMAP, POP3, Roundcube, SOGo CalDAV/CardDAV, ActiveSync FolderSync, FTP swaps, site PHP, phpMyAdmin, DNS Manager + BIND, cron task, protected folder, backup/restore, fail2ban from a network namespace, hourly/daily jobs, logrotate, service restarts). Any refusal fails; SELinux refusals are read from /var/log/audit/audit.log by time (ausearch -ts found nothing); AlmaLinux's hidden (dontaudit) refusals are reported after `semodule -DB` (normal noise: net_admin, siginh/noatsecure on transitions). About 20 minutes; run it on a work branch (installers_ref, core_ref) after changes that touch services, SELinux or AppArmor. Part of a release only as the **full test** (release.yml `full_test`, installers repo), asked for after a good number of changes (owner, 2026-11-12); last full test: 2026-11-13, release 2027.84. All three passed 31/31 with nothing refused. Daemon rules it taught: commands go through `ctrl_system::OwnFiles()` (only stdin/out/err), the daemon's report is opened with `>>`, the panel asks (is_readable) before opening other services' logs; Sentora's SELinux rules ride in platform-settings.json (`selinux_cil`) and sentora-update loads them.

## SOGo on AlmaLinux

built by Actions > SOGo bundle (`preconf/sogo-build/`: libobjc2 with clang,
GNUstep make/base, libwbxml, ytnef, SOPE, SOGo + ActiveSync) into /opt/sentora-sogo, published as
`sentora-sogo-alma10-amd64.tar.gz` with a list of the system libraries it needs (RUNTIME-LIBS; the
installer installs the missing ones). Service `sogo` (unit written by the installer, only
LD_LIBRARY_PATH), tool wrappers in /usr/local/sbin (setting sogo_tool_dir). New SOGo release: run the
workflow (version empty = newest), then the install test.

## Server Monitor process list

a root service `sentora-procstat` (installed and kept running by the
server_monitor daemon hook) writes /var/lib/sentora/monitor/procs.json; the page uses it while fresh
(SELinux hides other processes from the panel, and nothing in SELinux was widened for it).

## RHEL 10

the AlmaLinux installer, with a subscription check (`subscription-manager identity`),
CodeReady Builder via subscription-manager, and EPEL's release rpm from Fedora checked with its key.
Tested on the owner's registered RHEL 10.2 VM (2026-11-10, release 2027.69): post-install check 0 FAIL,
smoke test 26/26 with SOGo (15 minutes, RHEL's updates are slow). Cockpit (9090) is now switched off by the installer.

## Fedora 45

`sentora2027-fedora` (copy of the AlmaLinux installer: no EPEL, Dovecot 2.4 with the
Debian/Ubuntu settings, dnf5-plugin-automatic with /etc/dnf/automatic.conf made from dnf5's template,
BIND's real unit is bind.service, LLMNR off, DBD::MariaDB for SpamAssassin, mariadb client). Builds:
php-bundles and sogo-bundle take system fedora45 (SOGo on Fedora uses GNUstep base's master branch and
the OpenSSL 4 patch in build-sentora-sogo.sh). FEDORA_VERSIONS in the installer lists the tested
releases: a new Fedora needs its bundles built and the install test passing. Rocky Linux 10 and
Fedora 45 are in the install test (five systems). Tested on GitHub: Fedora and Rocky 0 FAIL, smoke 26/26. On the owner's Fedora 45 VM (2026-11-10, release 2027.70): post-install check 91 OK / 0 FAIL, smoke test 26/26 with SOGo, 7 minutes.

## Docker image (2026-11-11)

`ghcr.io/rustus/sentora2027` (public), `docker/` in the installers repo: Debian 13 + systemd + the deb13 installer; the first start of a container installs Sentora (`sentora-docker-firstboot`: panel address from `--hostname`, `SENTORA_EMAIL`, `TZ`; only step lines reach `docker logs`), so each container has its own passwords. Runs WITHOUT `--privileged`: `--cgroupns=private --security-opt writable-cgroups=true --tmpfs /run --tmpfs /run/lock --cap-add NET_ADMIN` (cgroups read-only stop systemd; SYS_ADMIN breaks it under Docker's AppArmor profile, so not used). FTP passive ports 61000-61099 in a container (60000-65535 overlaps the host's outgoing ports). Data lives in the container (no volumes: Sentora writes all over /etc); updates inside with sentora-update. `.github/workflows/docker-image.yml` + `.github/scripts/docker-test.sh` run the README's exact command, check 0 FAIL / smoke 26/26 / ports from outside; release.yml's `docker-image` job publishes `:<version>` and `:latest` after the core's release of that version is out. First published 2027.73 (91 OK / 0 FAIL, smoke 26/26, 5 minutes). Backups: `-v sentora-backup:/backup` and the first start switches on the off-server backup into it (no server = local folder); the test removes the container, starts a new one on the volume, restores (`sentora-panel-restore --full`) and compares the kept site (passed, 0 FAIL). `docker logs`: systemd sends PID 1's output to /dev/null, so `sentora-docker-init` (the image's CMD) starts `tail -F /var/log/sentora-docker.log` (moved into init.scope first) and then execs systemd; the first start writes there. README: "In Docker".

## PHP for sites (2026-11-12, after 2027.83, on master2027)

per-domain PHP settings (Apache Config > a domain > PHP settings; `sys_domainphp`, own pool web<acc>-d<domain>, 29 allowed names, help under the box), today's PHP defaults (`sys_phpdefaults`, only while at the old default, once), PHP Versions > Extensions (`sys_phpext`: inventory /var/lib/sentora/php-extensions.json, 40-sentora-<ext>.ini, fpm -t + load check, system packages via package_install + php_pecl_package; apcu settings are apc.*; ffi/xdebug/pcntl never), PHP builds 8.2+ carry apcu 5.1.28, redis 6.3.0, memcached 3.4.0, imagick 3.8.1 off (AUTO_ON=0; rebuilt for all four systems 2026-11-12), DNS Config reworked (tiles, ring, buttons left). Install test PHPDOMAIN covers per-domain settings, defaults and extensions (apcu added on all five). Released in 2027.84 (full test).

## Windows (WSL), first real run 2026-11-11

the owner's Windows 11 23H2 VM (VMware, nested virtualization), Debian 13 inside:
installed in 14 minutes, post-install check 91 OK / 0 FAIL (WARNs: no SELinux/AppArmor in WSL, self-signed), smoke test 26/26.
Fixed on the way (released in 2027.72): Windows PowerShell 5.1 mangled the quotes of commands passed to
wsl.exe (InLinux now sends base64 into a file run with --exec), the installer needs -d with -i, the script checks the
installer really finished (/etc/sentora/.installing gone), ends with a short login block, leaves double-click Status /
Backup / Uninstall files in Sentora's folder; read-only disks (WSL's kernel headers) no longer raise disk alerts.
Restart test passed (panel answered from another PC after a reboot). **Next (the owner's list, 2026-11-12):** the owner tests the panel; open from that night: 75 "Remote file inclusion" lines from his PC on the panel by IP (URI not seen yet: ask for a screenshot if they come back). His panel's time zone was America/Catamarca by mistake; fixed to America/Chicago (timedatectl + date.timezone in every php.ini). Debian VM confirmed 2027.65 (smoke 26/26).

## Payment Plans (add-on, sentora-repo/payment_plans.zpp, 108)

Menu (108, owner 2026-11-13): `code/menu.php` hides it from clients with no plan and no invoice (core 2027.89 `ui_moduleloader::Shown` asks a module's optional code/menu.php for each menu); sellers always see it.

billing belongs to each seller
(administrator or reseller); the panel's administrator does not manage it. Each seller turns it on or
off (off skips the payment check). The page opens with the seller's packages; each package has its own
page where its price is set (prices in the module's x_pp_prices; the panel's package tables are only read:
the owner's rule, nothing of the core or its tables changes for an add-on). Processors are files in the
module's `code/processors/` (PayPal, Stripe; `pp_pay::Processors()` lists them), and every payment is
checked by `pp_pay::Apply`. New clients on priced packages are billed automatically. Figures at the top
of the seller's page; every step noted in `x_pp_log`. Security-reviewed in 103 (see the CHANGELOG). Tried against real MySQL 8.4 and MariaDB 10.11/11.8 in the Add-ons workflow (PP_MYSQL, 2026-11-19); not yet against the real
PayPal/Stripe test modes; refunds and chargebacks (105): no webhooks (no public address, owner's rule: less exposed); `pp_daemon` step 4 asks each processor's `Reversals()` once a day for invoices paid within 180 days and while a chargeback is open (columns in_refund_vc, in_refunded_in, in_dispute_vc, in_checked_in), `pp_pay::Reversals` notes it on the invoice and history and tells the seller (`pp::TellSeller`); the client's hosting is never touched by it. The module is only kept as the .zpp: unzip it to change it. Its tests:
`tests/payment_plans/run.sh` in sentora-installers-2027 (stand-in panel, fake PayPal and Stripe; run
before publishing a change; `run.sh <unzipped module folder>` tests a working copy, without it the .zpp).

## Web Hardening (add-on, sentora-repo/php_hardening.zpp, 106, administrators; folder php_hardening, was "PHP Hardening")

two guards, each off / watch only / on. **ModSecurity:** the installers install it switched off (settings modsec_*; the core's `etc/modsecurity/coreruleset` is OWASP CRS 4.25.2 LTS, updated with `.github/scripts/update-crs.sh`; Sentora's httpd.conf includes modsec_conf_dir); the add-on's daemon writes `sentora-web-hardening.conf` there (`ph_modsec::Conf`), tests Apache, reloads, rolls back; the panel's own host is always DetectionOnly (106: ModSecurity 2.9.8+ refuses form data over SecRequestBodyNoFilesLimit even in DetectionOnly, so watch only uses 12.5 MB and the panel's hosts skip bodies over 128 KB); what it caught comes from the audit log (parts AHZ). The install test switches it on through the add-on and requires 403s for attacks (`.github/scripts/modsec-test.sh`). **Snuffleupagus:** the PHP bundles carry it switched off; Actions > PHP bundles also builds it for each system's own PHP (on the release, in php-bundles.json under `snuffleupagus`); rules in `ph::Catalog()`; the daemon writes 90-sentora-php-hardening.ini and the rules into that PHP's own scan dir, tries PHP with them first, reloads, puts back on failure; what it caught comes from `journalctl -t snuffleupagus`. Not used: read-only-exec (sites own their files) and anything needing a secret key (the rules file is world-readable). Tried on the owner's AlmaLinux panel (2026-11-12, both guards, checked out OK); the ModSecurity part is also tested by the install test; Snuffleupagus builds for every system's own PHP are on the `current` release (alma10 8.3, debian13 8.4, ubuntu26.04 and fedora45 8.5; checked 2026-11-11), so it can be tried now. What it caught (102-103): `assets/ph.js` + `code/caught.php` (module_controller::Feed: list pages before/after an id with filters q/kind/act/guard via ph::Where, or stats=1&days via ph::Stats); every line has ca_kind_vc (ph::KindOf: CRS families / Snuffleupagus feature), daily counts in x_ph_daily (400 days), list keeps 5000; Look now = zsudo daemon-run once a minute. The panel's own names (ph_modsec::PanelHosts: sentora_domain + Branding addresses) and requests to a bare IP are DetectionOnly. ModSecurity 2 writes zones as "--0600" (Entries fixes it). Tests: `tests/php_hardening/run.sh` (real Snuffleupagus, fake PHP-FPMs and Apache).

## zGod (add-on, sentora-repo/zgod.zpp, 100, administrators; owner's request 2026-11-23)
The owner's zGod for ZPanel (2011, admin/zGod) written again: the panel's tables read across every account and reseller
(code/zgod.php, every value bound; FTP passwords never read out), tabs Overview (tiles, bandwidth by month, a ring of this
month's users, quotas at 80%+), Accounts (card rows, filters: search, reseller, status, order), Resellers, one account in full,
Daily report. Two actions only, as the panel's own modules do them with their hooks and a line in x_logs (module zgod):
suspend (ac_enabled_in 0, ws_suspend's reason when the panel has it; never an administrator or oneself) or switch on, and a
mailbox off or on. The old zGod's holes are gone: it included any file a form named, built SQL from form values, restarted
services from the web page, "locked" by changing passwords and a hidden package 911, edited bandwidth counters, had no form
key. Daily report (OnDaemonDay, sys_email, off by default; to the chosen addresses or every administrator's). Own table
x_zgod (settings only). Test: tests/zgod (stand-in panel on SQLite; views, actions, refusals, an attack test: hostile names,
injection tries, no or a wrong form key, a reseller and a client). The owner's later ideas (not started): a Kibana dashboard
for zGod's figures, and installing a dashboard file on the Elk node with one command.

## Patch scripts (installers repo, 2026-11-10)

`sentora2027-patch-ub26.04`, `-deb13`, `-alma10`, `-fedora`, run on a fresh server before the installer (not part of it, not on the release; got from raw.githubusercontent master2027): all of the system's updates, Sentora's stopgaps for holes the system has not fixed (each removes itself; so far CVE-2026-98432, an nft filter for TCP with IPv4 options until a fixed kernel runs), then whether to restart. The header of each says what was checked and why. Tested on the owner's Ubuntu 26.04.1 VM (kernel 7.0.0-42 to -43, filter on, restart, filter and its service gone). A new hole: add a stopgap function to the four scripts and a line to their headers.

## Security watch (installers repo, Actions > Security watch, Mondays)

`.github/scripts/security-watch.py` looks at CISA's KEV list (software a Sentora server runs) and the newest releases of what Sentora ships itself (PHP builds, Roundcube, phpMyAdmin, Matomo, SOGo, OWASP CRS, Snuffleupagus). It changes nothing; findings (and checks that could not run) fail the run so GitHub emails the owner; seen findings go in `.github/security-watch-ack.txt` with a note. Its first finding: Roundcube 1.7.6 (17 security fixes), now in the core and released in 2027.71. Roundcube, phpMyAdmin and Matomo are Sentora's own copies in the core's etc/apps (not from apt/dnf): only a core update and release patch them.

## Sentora backup node (2026-11-12, owner's request)

`backup-node/sentora2027-backup-node` (installers repo; same screen and platform block as the node installer: FAMILY deb/el/fedora, ufw on deb, firewalld elsewhere; options --panel, --panel-code, --key, --name (variable AREA: os-release has NAME), --gate, --update) + `backup-node/sentora-backup-gate` (forced command `restrict,command="/usr/local/sbin/sentora-backup-gate AREA"` in /etc/ssh/sentora-backup/authorized_keys, root 644, Match block in sshd_config between `# BEGIN/END Sentora backup node`; area /srv/sentora-backups/AREA; refuses shell characters; `inside()` keeps paths in the area with realpath and KEEPS a trailing slash; rsync --server options checked, --link-dest/--compare-dest/--copy-dest as `=` or as two words; verbs hello, ready, begin, latest, finish, count, list; log /var/log/sentora-backup-gate.log). Tool `sentora-backup-node list|add|remove|fingerprint`. Panel side (core): `bin/sentora-backup-common` (offsite_hostkey pinned in a known file, StrictHostKeyChecking=yes once set; is_gate and there_ready/begin/latest/finish/count use the gate's verbs, plain SSH servers as before), backup_admin's daemon hook makes /root/.ssh/sentora_offsite and stores offsite_pubkey; the page's panel "A Sentora backup node" shows the install command. Tests: Actions > Backup node test (`.github/scripts/backup-docker-node-test.sh`, five systems, `core_ref`). The panel side reaches servers with the next panel release.

### The node way (2026-11-13, owner: "one way to install a node"; core extended, owner's OK)

- Core `dryden/sys/nodes.class.php` (`sys_nodes`): PanelCode(type, pubkey) = first 8 base32 letters of sha256("type|pubkey") as XXXX-XXXX; SshKeyCode(line) = first 16 letters of base32(sha256(key blob)); NodeKey('SB1', code) = SB1-XXXX-XXXX-XXXX-XXXX; ParseKey; SshFingerprint; ScanSshKey (root). Base32 without I, O, 0, 1. The bash lines (installers repo NODES.md, between `# BEGIN node codes` / `# END node codes`, the same in the backup node installer; the backup node test checks both are equal and that PHP and bash give the same codes). NODES.md also has a template installer and the rules a node keeps.
- `modules/backup_admin/code/node.php?key=1` (no login) answers offsite_pubkey as text (503 until the daemon made the key).
- Installer: `sudo bash sentora2027-backup-node [--panel URL] [--panel-code C]` fetches that key (curl, then -k for a self-signed panel: safe, the person checks the code), shows PanelCode('sentora-backup', key), y/n; ends with the node key; `sentora-backup-node key` shows it again. `--key` (2027.85 panels) still works and the ending also prints the SHA256 fingerprint for them.
- Backup Config (Module Admin > Backup Config > Off-server backup): "Add the backup node" (address + key) stores `offsite_nodekey` = "addr port code", request `pair <time>`. The daemon hook scans, compares SshKeyCode, then sets server/port/user sentora-backup/password ''/path '/'/hostkey (fingerprint)/enabled true, removes /root/.ssh/sentora_offsite_known_hosts, `offsite_pairing` = "ok|fail <time> <words>", then a connection test. Tested with a SQLite harness (wrong key: nothing changed; right key: paired) and in the backup node test (the panel's ScanSshKey of the real node gives the key the installer showed).
- `bin/sentora-backup-common` pin_hostkey: a remote machine with no OB_HOSTKEY is refused (before: first key seen trusted). The hook adopts the ed25519 key in root's known_hosts for a machine that worked before; otherwise it stores the scanned key in `offsite_seenkey` and the page shows it with a "trust this key" button (doTrustKey).

### Where the backups go (2026-11-13, owner's yes: "a logical flow")

- `offsite_target` = node | ssh | folder ('' on older panels: `module_controller::Target()` works it out). The tab shows a choice bar (`getOffsiteDest`, links `?dest=`), the form shows only that destination's fields (`getOffsiteForm`; `doSaveOffsite` takes `inDest` and keeps the other destinations' settings; a node's form only sets switch, time, nights, mailboxes; a folder clears the server).
- A node is "in use" (`NodePaired`) when target node, server and hostkey are set: `NodeCard` (green Working / orange Needs a look: switched off, no copy for 26 h, last copy failed, disk >= 90% / red Not reachable / blue Checking) from /var/lib/sentora/offsite-backup.json (check_ok, check_time, node_free/size/used from the gate's new `space` via `sentora-offsite-backup --check`, last_ok); `doRemoveBackupNode` clears server, key, pairing, target, switches off (the copies on the node stay).
- Fast lane: Add the backup node, Test connection, Back up now and Trust key call `RunNow()` (zsudo daemon-run, as Sentora Config's "Run the daemon now"); while `Pending()` (a node key or a request waiting) the status side reloads the page every 5 s, at most 24 times (sessionStorage).
- Daemon hook: pairs whenever offsite_nodekey is set (not only with request "pair": a Test connection pressed meanwhile replaced it before), sets target node; queues a connection test every 30 minutes for a node in use (the card's data).
- Disk chart (owner, 2026-11-13: "a stat style graph of disk space"): `SpaceChart()` at the top of Status, a ring as on DNS Config (this panel's copies, other files on that disk, free; orange when 90% full) with disk size and nights kept, for every destination: `sentora-offsite-backup --check` stores space_free/space_size/space_used/nights (a node's gate `space`, elsewhere df/du there, `there_count`).
- Tested: a SQLite harness (10 checks: each destination saved, a node form before pairing refused, pairing after a Test connection, the node's form keeps its server and folder, the 30-minute check, Remove) and screenshots of 9 states, light/dark, desktop/390 px. The backup node test checks `space`.

## Docker Hosting (add-on, sentora-repo/docker_hosting.zpp, 141, node agent 120; built 2026-11-11 at the owner's request)

### What it is

- containers on separate servers ("nodes"), never on the panel's; zero core changes.
- Every app starts behind Caddy basic auth (user setup, password on the customer's page, only its bcrypt hash goes to the node) until "Open to the public".

### Panel side

- Panel side: `code/dh.php` (tables x_dh_*, shares, nodes, containers, DNS as DNS Manager, jobs), `code/proto.php` (messages: Ed25519-signed, sealed to the receiver with X25519, time + nonce, replies echo the nonce; nonces kept in x_dh_nonces), `code/node.php` (the nodes' address, no login), `code/catalog.php` (Uptime Kuma, Gitea, Vaultwarden, Nextcloud: full image names) plus the administrators' own apps (107: table x_dh_apps, ids my-..., `dh::CheckApp`/`SaveApp`/`RemoveApp`, env encrypted, {domain} and {secret} = `dh::AppSecret`; port and folders fixed while in use), controller, daemon hook (account off/deleted, disk limit). The panel's keys are kept with ctrl_secret (server_crypto_key); if that secret changes the page says so and keys are never made anew (nodes trust them). Shares (x_dh_grants) pass down: admin unlimited, reseller gives clients part of its free share; apps intersect with the giver's. Units: CPU hundredths, MB.
- Docker plans (123, owner: limits a client can never pass, apart from the panel's packages): x_dh_plans per seller (`dh::Plans/Plan/SavePlan/RemovePlan/PlanClients`), x_dh_grants.gr_plan_fk (0 = own amounts); `SetGrant` with v['plan'] takes the plan's amounts with the same checks; a changed plan is applied to each client through SetGrant (failures named in dh::$why); Shares tab = card rows (`SharesHtml`: &plan= / &share= open under the row). `Adopt`/`AdoptRead` check the owner's share (apps, then the real size).
- Node choice (125): `Deploy` takes v['node'] (admins only, checked against Online/Room), controller `NodeChoice()` box in Start an app, 'On node X' on app rows for admins; a little crossover between add-ons/core is fine if nothing breaks without the other (owner, 2026-11-12).
- Advanced (112, agent 106): the app's owner adds NAME=value lines (`dh::SetOwnerEnv`, encrypted in dc_env_tx, the one before in dc_envprev_tx, state 'applying'); CreateData merges them over the app's env; job 'recreate' = the agent's Update without a pull (same image ID), and `Failed()` puts the old settings back. Agent before 106: refused.
- Notices, docker run, registries (130): dh_daemon::Notices (key docker-hosting-node-<id>, scope groups [1], closable login, dh::Words($n, false); setting notice_nodes); dh::FromDockerRun + doAppFromRun (fills $_POST, self::$appForm = ''; Say('warn') = amber); registries in dh setting `registries` (ctrl_secret encrypted JSON host => user, secret, at), dh::RegistryHost/SaveRegistry/RemoveRegistry/RegistryFor, card RegistriesHtml; agent Pull() (--authfile in /run/user/<uid>, 0600, deleted after).

### Node side

- Node side (installers repo `docker-node/`): `sentora2027-docker-node` (since 124 / agent 110 every supported system: Ubuntu 26.04, Debian 13, AlmaLinux/Rocky/RHEL 10, Fedora 45; FAMILY el/fedora/deb, what differs in the platform block at the top and in /etc/sentora-docker-node/platform.conf, which the agent's P() reads; firewalld, or ufw where already on; Debian/Ubuntu's Caddy 2.6 needs `basicauth` + base64 hash (agent CaddyOld) and HOME; their podman-restart skips unless-stopped apps, so the agent's BootStart starts its apps once per boot (mark in /run, not those stopped by their owner). On AlmaLinux: EPEL+CRB as the Sentora installer, podman, php-cli + php-sodium, Caddy from EPEL else COPR @caddy/caddy, firewalld, user@.service Delegate=cpu cpuset io memory pids, SELinux: ports 20000-29999 as http_cache_port_t + httpd_can_network_relay, newuidmap caps checked, logind must not be masked) and `sentora-docker-node-agent.php` (root service sentora-docker-node; user sdh<account> per customer with linger and podman-restart; containers sdh-<id>, volumes sdh-<id>-<n>, 127.0.0.1:2xxxx; Caddy sites in /etc/caddy/sentora/sites.caddy, validated, rolled back; every job checked again, no shell).
- Node setup: `sudo bash sentora2027-docker-node` asks the panel's address, the person checks the panel's code (`dh::Fingerprint`, shown on Nodes and apps; node.php?key=1 gives the public keys), the node shows its key SN1-xxxx (120-bit secret, base32 without I/O/0/1; root-only /root/sentora-docker-node-key.txt, removed once linked); "Add a node" stores sha256(secret) in nd_claim_vc; the node's 'hello' (sealed, self-signed, carries the secret) gets 'pending' or 'linked'. Old command flow (pair + code) still there.
- Node jobs (jb_ct_fk 0): node-update, sys-update {security}, reboot (shutdown -r +1), check-updates, settings {email, local_certs} (agent rewrites Caddyfile + install.conf), uninstall (systemd-run --on-active=30 sentora-docker-node-uninstall --purge; the panel forgets the node on its result, or after 10 quiet minutes).
- Updates: `sentora-docker-node-update [--yes|--check]` on the node (downloads installer + agent from master2027, or SNODE_RAW_BASE; runs the installer with --update, which keeps node.json and /etc/sentora-docker-node/install.conf (EMAIL, LOCAL_CERTS), touches only Sentora's own software (owner, 116: Podman/Caddy/PHP come with the system's updates; missing packages are still added), keeps the old agent as .previous and puts it back if the service does not stay up); raise `snode::VERSION` with every agent change (the panel shows it). Since agent 105 messages go as a form field `m=` (urlencoded; text/plain tripped ModSecurity); node.php takes both, and the agent falls back to bare for a panel before 108.
- Newest agent version looked up once a night, 01-05, and on the first run (ETag; latest_agent, 114; owner: GitHub nightly); behind = "Update available" on the row + "Update now" in Manage; switch Nightly node updates (nd_autoagent_in, on by default, owner 115) queues node-update at 02-03; a module update makes the panel look again at once (sha1 of daemon.php in latest_agent_sig).
- App updates: job 'update' with CreateData (agent: pull, compare image IDs, rename old to -old, create, start, wait SNODE_UPDATE_WAIT=20 s, else roll back); daily skopeo digest check -> states.update -> dc_update_in; dc_autoupd_in updates at 02-05 (daemon; DH_TEST_HOUR in tests). sentora-docker-node-sysupdate (--check/--security/--all) writes /var/lib/sentora-docker-node/sysupdate.json, reported as sys_* in the node's info.
- Agent: `Scan` (hourly, label sentora.cid, address/auth from sites.caddy), states carry image/domain/hostport/volumes/public, `relink` (+ /usr/local/sbin/sentora-docker-node-relink: same keys = new address only; else new link, state's done/results dropped, old node.json kept as .old-*).

### Recovery, backups and moves

- Recovery (122, agent 109, owner's request): apps a node reports that the panel does not know are NEVER deleted by themselves: `dh::Found` lists them in x_dh_found (and reserves the number with a deleted 'reserved' row in x_dh_containers when `NearNumber`; at most MAX_COUNT per node); only an app of this node the panel deleted is deleted again (the old rule). `dh::Suggest` (old row of a dead node = give back as it was; else the domain's owner; else the same account number, warned), `dh::Adopt` (admins; queues node job 'inspect', the env goes encrypted to dc_keepenv_tx via `AdoptRead`, merged in CreateData between the app's and the owner's env, never into jobs' results) and, when the user, number or port must change, job 'move' (agent `Move`: image save/load between users, volume export/import, rollback; `Failed` puts the reservation back). Missing apps (state 'missing', `Act` 'remake'); `dh::Rehome` (node away > 10 min, Manage > Start its apps on other nodes, `DnsRepoint`); `dh::NewKeys` (keys unreadable).
- App backups (127, agent 111): `code/backup.php` (dh_backup: x_dh_backups, Start/Restore/Move/Put/Get/Result/Give/MoveOn/MoveDone/Daemon; folder /var/sentora/backups/docker-apps made by the root daemon for web_user, override DH_BACKUP_DIR; only the newest per app kept, state 'replaced'; Unavailable() = offsite_enabled and `gate` 1 in /var/lib/sentora/offsite-backup.json (core 2027.85), override DH_OFFSITE_STATE; nightly at offsite_time minus 2 h); messages 'put'/'get' in dh::Receive (pieces of 2 MB, token per transfer, node checked); container states saving/restoring/moving; a moved app's old copy is deleted by a job on the old node (jb_ct_fk = the app, ignored by Result there, skipped by Found while queued); FreePort leaves dh_backup::Reserved ports. Agent: Backup (export as the user, moved to root's /var/lib/sentora-docker-node/transfer/<token>, gzip -1, Upload; 'move' keeps it stopped), Restore (Download + sha256, safety export, volumes made anew, rollback), SNODE_RETRY_WAIT for tests.

### Security

- Security review (120, agent 107): Poll/Result only touch a container whose dc_node_fk is that node; pair/hello limited by dh::TooMany (LINK_MAX per IP in 10 min, nonces keep nn_ip_vc/nn_link_in); the agent's Fence() keeps nftables `inet sentora_docker_node` (set of sdh* uids, link-local refused: cloud metadata); downloads --proto =https,file; sites.caddy 0640 root:caddy.
- Local network (121, agent 108, owner: per customer, off by default): private ranges refused to `@apps` unless the uid is in the set `lan` (DNS servers from resolv.conf allowed; link-local always refused); setting `lan_accounts` (comma list), `dh::SetLan` (admins only) queues job 'network' {lan_users} to every node >= 108, the agent keeps /var/lib/sentora-docker-node/network.json and reports cfg_lan_users, Poll re-queues on a mismatch; card in Nodes and apps (`LanHtml`).
- Agent 115 (security review 2026-11-15, owner: "could someone gain root"): root never writes, renames or chowns in a customer's home: exports/imports and image save/load go through Podman's output/input to root's own transfer folder (`RootTemp`), through a pipe the user's own bash makes (`AsUser` with $in/$out: `podman volume export` reopens /dev/stdout, which on Debian may not open root's file); the registry login is written by the user itself and deleted by it; skopeo runs as the user; the fence rejects new connections from `@apps` to 127.0.0.0/8 and ::1 (Caddy admin :2019, other customers' ports); the sysupdate log is 0600.
- Extra isolation, gVisor (136 / agent 116, owner 2026-11-15: on by default; administrators may switch it off per app, also while shadowing; a node marked Trusted (my own apps) starts new apps with it off; apps made before keep `standard` (migration: setting guards_from)): dc_guard_tx JSON {iso}, dc_iso_vc what the node reports, nd_trusted_in; `dh::Guards/SetGuards` (recreate job), controller `GuardsHtml` (Settings > Security). Node: installer step "Extra isolation for the apps (gVisor)" (release tarball, SHA-512, /usr/local/bin/runsc + wrapper `runsc-sentora` = `runsc --ignore-cgroups --network=host --overlay2=none`, RUNSC= in platform.conf; no gVisor = standard runtime, the panel says so). Why so (gvisor-probe workflow + real-node runs): under rootless Podman runsc can not use systemd cgroups (refused) nor cgroupfs; with --ignore-cgroups it has no limits, so the agent puts each app in its user's slice `sentora-app-<cid>.slice` (`SetSlice`: MemoryMax mem+64M, MemorySwapMax 0, CPUQuota, TasksMax 4096; limits jobs change the slice) and **starts it from inside the slice** (`systemd-run --user --scope --slice=...  podman start`): rootless Podman leaves conmon (and so the sandbox) in the caller's cgroup when the user owns it, so --cgroup-parent alone left apps outside with no limits. After every start `InSlice` checks /proc/<conmon,pid>/cgroup; outside = one more try, then stopped with Podman's words (never runs unlimited); BootStart restarts one podman-restart left outside. Restart = stop + start, retried while pasta still holds the port ("Address already in use"). --overlay2=none: gVisor's own overlay left `.gvisor.filestore.<id>` after a hard stop and the app would not start again. --network=host means gVisor uses the netns Podman/pasta made for the app (its own namespace, not the node's).
- Image check, Trivy (137 / agent 117, owner 2026-11-15: **Warn by default**, chosen after the Trivy probe showed Block would refuse Uptime Kuma, Gitea and Nextcloud as published): guards['scan'] warn|block|off (`dh::Guards`, admins only via SetGuards, no restart), dc_scan_tx = the node's summary (`dh::ScanSummary`: at, id, c/cf/h/hf/m/l counts, up to 8 plain 'pkg ver > fix CVE' lines), Settings > Security shows it (select for admins). Agent: `Gate` after the pull in create and update (block: create refused if cf > 0, update refused if cf > the running version's), `ImageCheck` = podman image save as the customer (pipe) into /var/lib/sentora-scan/in (root:sentora-scan 750, file 640), then `systemd-run --scope -p MemoryMax=1536M runuser -u sentora-scan trivy image --input ... --skip-db-update --skip-java-db-update --scanners vuln --format json` started in /var/lib/sentora-scan (it reads trivy.yaml from its cwd), JSON to root's temp file; nightly in CheckUpdates: `ScanDb` then every app re-checked (only told). A create the check refused: state error, note 'Create failed: refused by the image check', row has Try again (Act remake). Installer step "Image check for the apps (Trivy)": TRIVY_VERSION + SHA-256 per arch pinned (0.76.2; take a new one only after the Trivy probe workflow has listed and checked it: 0.69.4 was the poisoned one in March 2026), user sentora-scan, DB at install; TRIVY= and SCAN_USER= in platform.conf; uninstall --purge removes them. DB ~1.7 GB, a scan ~300 MB for seconds. Java jars are not looked up (no Java DB).
- Web firewall, Coraza (138 / agent 118, owner 2026-11-16, **On by default**; apps made before it: Watch via the waf_from migration): guards['waf'] on|watch|off (`dh::Guards`; a trusted node keeps it on: it guards the app from the internet), dc_waf_vc = what the node reports ('none' = no firewall there), `dh::WafJob` queues a 'proxy' job with guards (site rewritten, app not restarted); the panel re-sends it when a node reports another mode. Agent: `Waf()` = CADDY_WAF=1 in platform.conf, `WantWaf`, container state 'waf'; `Proxy()` writes snippets (sentora_waf_on / _watch: load_owasp_crs, coraza.conf-recommended, crs-setup.conf.example, @owasp_crs/*.conf, SecRuleEngine On|DetectionOnly, SecRequestBodyLimitAction ProcessPartial, SecResponseBodyAccess Off, SecAuditEngine Off) and per app `route { import sentora_waf_X; reverse_proxy }` after basic_auth; sites rewritten once per agent start (after Scan). Caddy with Coraza: workflow caddy-waf.yml (build + probe of the stock apps + memory + publish as release caddy-waf-<caddy>-coraza-<ver>, never latest; build is reproducible); installer step "Web firewall for the apps (Caddy with Coraza)" pins CADDY_WAF_TAG and SHA-256 per arch, installs /usr/local/bin/caddy-sentora (semanage httpd_exec_t), drop-in caddy.service.d/sentora-waf.conf (ExecStartPre validate, ExecStart, ExecReload: the EL/Fedora unit validates with its own Caddy before starting), CADDY_BIN switched in platform.conf; purge removes it. A new Caddy or Coraza: run the workflow with the versions (publish on), pin the new SHA-256s. What it refused (141 / agent 120): `WafStats()` reads `journalctl -u caddy --since -24h -o cat -g http.handlers.waf` every 10 minutes (no -g: all lines), "WAF rule violation detected" lines (hostname, uri, unique_id; On only) joined with the rule lines by unique_id (949/959/980 skipped), per host n + last 5 {at, path without query, why}; States 'waf_stat' -> dc_wafstat_tx; Settings > Security. Caddy's global options (installer and `Settings()`, with CADDY_WAF=1): `log { exclude http.handlers.waf }` + `log sentora-waf { include http.handlers.waf; level ERROR }` (Coraza wrote ~30 "key not found in collection" warnings per request).
- gVisor and SELinux (2026-11-16, found on the real SELinux machine): runsc refuses an OCI spec with an SELinux label, and run under container_t (runcon) it can not create its own state folder. So `Runsc()` returns '' while getenforce says Enforcing: those apps use the standard runtime (container_t), dc_iso_vc 'standard' ("asked for, not in force"). **Owner 2026-11-18: keep this; Sentora's own SELinux rules for gVisor later (Waiting on the owner).**
- Docker node test alma10-vm: the same docker-node-test.sh on an AlmaLinux 10 cloud image under KVM (in_box over SSH in /, box_cp, box_restart = reboot; the panel at 10.0.2.2), SELinux enforcing; every AVC/USER_AVC/SELINUX_ERR fails it.
- Repeat attackers, CrowdSec (139 / agent 119, owner 2026-11-19: **local by default**, "Share with the CrowdSec community" an administrator's switch per node): probes (crowdsec-probe.yml, scripts crowdsec-probe*.sh) found Debian/Ubuntu ship 1.4.6 (2023), EL/Fedora none, CrowdSec's packagecloud repo 1.8.2 for all, and that the package registers with CrowdSec's central API at install and pulls its list at first start. Installer step "Blocking repeat attackers (CrowdSec)": apt repo (packagecloud any/any, key fingerprint 6A89E3C2303A901A889971D3376ED5326E93CD0C checked) or rpm repo (rpm_any, gpgcheck + repo_gpgcheck), /etc/crowdsec/config.yaml.local `online_client: null` written BEFORE the package on EL/Fedora (no contact at all); on Debian/Ubuntu the package's postinst always runs `cscli capi register` and fails without CAPI, so there it registers once with policy-rc.d holding its services, then the override is written and the credentials removed before the first start; collections linux + sshd + caddy, acquis.d/sentora-caddy.yaml (/var/log/caddy/sentora-access.log, httpd_log_t), safe list parser s02-enrich/sentora-safe-list.yaml (never empty: CrowdSec refuses an empty whitelist), bouncer key, CROWDSEC=1 in platform.conf; /var/log/caddy/sentora-access.log made as caddy:caddy 0640 (Proxy too, before `caddy validate` as root). Agent: `CsSettings` (job 'crowdsec' {share, safe}: writes the safe list + the panel's own addresses + 127.0.0.1/::1, share on = config.yaml.local without the override + `cscli capi register`, off = override + credentials removed; restarts crowdsec), job 'unblock' {ip or range}, `CsInfo` in Info (on, share, count, up to 100 blocked: ip, scenario, seconds left; asked once a minute), `Proxy()` adds `import sentora_log` (roll 20 MiB x3). Panel: nd_cs_tx {share, safe, sent}, `CsWanted/CsAddr/CsSend/CsSafe/CsUnblock`, settings sent once the node reports CrowdSec and again when sharing differs; node page section `NodeCrowdsec` (switch, blocked rows with Unblock, safe list textarea, action NodeCsSafe). The real-node test adds a block by hand (cscli decisions add), sees it in nftables and the panel, Unblock removes it; it checks CrowdSec's log shows no central API contact. Sharing on is tested only locally (it would register a CI machine with CrowdSec each run).
- Website docker-hosting/: every guard live (CrowdSec added 2026-11-19).
- Node updates are trusted by HTTPS from GitHub only (no signature): **planned for near the end of the project, after development (owner, 2026-11-12):** nodes update from published releases only (not master2027) and check a signature made with a key the owner keeps off GitHub (public key put on the node at install; a backup key), so a GitHub account takeover or a half-finished push can not reach the nodes. Remind him then; meanwhile two-factor login on GitHub.

### Look and behaviour of the page

- Look (140, owner: "more pleasing to the eye"): `Fold($card, $hint, $open)` turns a card into `<details class="dh-card dh-fold">` (Add a Docker node open while there is no node or after AddNode; Your own apps open with ?app=, a form being changed, App/AppRemove; Registry logins after Registry/RegistryRemove); app notes in `.dh-notes` under the row (grid-column 2 / -1, 1 / -1 on a phone); `ShareTiles` tiles with `.dh-bar` (warn colour at 90 %), "gave" only when > 0, administrators: "Room on the nodes" (online nodes that take new apps: Room() free of CPU x CPU_OVER, MEM_SHARE and DISK_SHARE). The add-on test saves every main page with DH_PAGES=<folder> (admin-apps, -nodes, -node, -shares, -log, client-apps, -settings, security, node) to look at with the panel's style sheets.
- Look (126): every text box needs type="text" (the panel's Bootstrap styles boxes by their type only; .form-control means nothing there), tabs as the reworked pages (bar, open tab underlined), buttons first on the left in .dh-foot, every list as .dh-item card rows (also Your own apps: &app= opens its form), dh.css is one ordered file: change the rule in its place, do not add overrides at the end.
- Widget and notices (134): assets/widget (widget.xml/php/css, class dashboard_widget_docker_hosting) copied by dh_daemon::Widget into <sentora_root>/etc/dashboard-widgets/docker_hosting when it differs; short labels in its $labels; dh_daemon::Notices keeps only offline, gone, disk, missing, app-*. Removing a node (132-133): RemoveNode($id, $by, $confirm) deletes its apps (and counts found apps) once the name is typed; Forget keeps nd_gonesign_vc/nd_gonebox_vc and queues uninstall {purge}; dh::GoneNode answers a removed node only with that job, then drops the keys.
- Page behaviour (129): Button() posts back to the same place (`Here()`: tab + manage/settings/plan/share/app); `Behind()` lists nodes behind the newest agent, `UpdateAllButton()` / doNodeUpdateAll (Update all (N), asked first); NodeButton shows "<label> (queued)" / "(in progress)"; WarningsHtml: one line per node, short words per dh::Warnings key, fix buttons under it (emails keep dh::Warnings' sentences).
- Lists (117): apps and nodes are card rows like Manage Clients (.dh-list/.dh-item: avatar, .dh-main name+pills+.dh-sub, .dh-switches, .dh-act, .dh-more opened under the row via &settings= or &manage=), stacked under 1100/640 px; node job buttons via NodeButton show Queued / In progress while dh::NodeJobs has that job (118); stopped apps and offline nodes red as Services' down rows (119, --sn-bad*); the other .dh-table tables become blocks under 640 px. New columns are added by dh::AddColumns on older installs.
- The app row (110-111): status in words, switches Nightly updates (dc_autoupd_in) and Open to everyone (dc_public_in, asks first), Update/Stop/Start/Restart/Logs/Settings (size, `doCtSettings`)/trash in the panel's btn-sm style with icons; the node's Manage has a Nightly security updates switch; the red Needs a look box is above the tabs for administrators (109).
- Warnings: dh::Warnings, mailed by dh_daemon::Warn (sys_email, nd_warned_tx).

### Tests and what was tried

- Tests: `tests/docker_hosting/run.sh` (519 checks; stand-in panel on SQLite or DH_MYSQL=host;port;user;pass;db, real agent, fakebin/ podman etc.) and Actions > Docker node test (`.github/scripts/docker-node-test.sh`: real nodes on all five systems in systemd containers, Uptime Kuma pulled and run, limits read from the container's cgroup (gVisor apps: their slice, and their processes inside it after each start, restart and node restart), setup password, public, a busybox probe can not reach the node's 127.0.0.1, restart, delete; also runs run.sh on MySQL 8.4, MariaDB 10.11, 11.8).
- Tried on the owner's own servers (2026-11-11): real panel (AlmaLinux) + node on AlmaLinux 10.2 with SELinux enforcing: node linked (self-signed panel: --no-tls-check, now added by itself), Uptime Kuma started at kuma.hemsaw.local, setup password, admin account made, opened to the public, no SELinux refusals (Caddy's own trust-store install was refused until skip_install_trust; LAN test with LOCAL_CERTS=1 in /etc/sentora-docker-node/install.conf). His node updated to agent 106 through the panel (2026-11-12, Docker Hosting 118 and the 7 renamed add-ons updated, all OK). Advanced (an app's own settings) tried on his node, works (2026-11-12).
- Not yet tried: a real domain with Let's Encrypt, a reseller/client share on the real panel, a move between two real nodes (only a stand-in second node in run.sh).

### Later (ask first)

- Later ideas (ask first): more nodes per customer, restoring an older night of an app from the backup node, one node shared by two panels (back burner, owner 2026-11-12: today a node trusts one panel; sharing needs per-panel users, ports, Caddy files and LAN lists, since account ids collide).

## Elk (add-on + Elk node; owner's request 2026-11-20)

The owner's choices (2026-11-20): administrators only see the logs (clients keep Log Forwarder for their own sites;
per-client Kibana later); the panel server sends first, the Docker and backup nodes later; Kibana with its own login on
the node (https://<node>/, `sentora-elk-node kibana-from` limits who reaches it). The node starts on Ubuntu 26.04 (owner:
"start with Ubuntu 26.04"); other systems later. The ELK stack is on a server of its own, never the panel's.

- **Node** `elk-node/sentora2027-elk-node` (installers repo), the node way (NODES.md, prefix SE1): Elastic's 9.x apt source
  (key fingerprint 4609 5ACC 8548 582C 1A26 99A9 D27D 666C D88E 42B4 checked); Elasticsearch on 127.0.0.1 with security on
  (heap 40% of memory, at most 31 GB; ML off), Logstash (1 GB) taking the panel's lines on TCP 6514 over TLS, only with
  the panel's certificate (`ssl_certificate_authorities` = that certificate alone), Kibana (1 GB) on 443 with its own TLS
  (AmbientCapabilities, not root) and the login `sentora-admin` (role sentora_admin: read logs-sentora-*, Kibana all; can
  not delete). The node's own small authority (/etc/sentora-elk/ca.key, root's) signs the node's certificate (CN
  sentora-elk-node, shown with the authority) and Kibana's. Retention by ILM `sentora-logs` (30 days; `sentora-elk-node
  retention`). ufw: SSH, 443 from `kibana-from`, 6514 from `panel-from` (the panel's address). Tool
  `sentora-elk-node` status/key/password/retention/kibana-from/panel-from/firewall/update. Needs 4 GB (refuses less).
- **Rules and dashboards** (installers repo `elk-node/`): `logstash/sentora.conf` reads 16 kinds (sentora.log: web-access,
  web-error, panel-access, panel-error, panel-login, mail, dovecot, auth, ftp, ftp-login, dns, fail2ban, zsudo, daemon,
  problems, webmail) into ECS fields (+ GeoLite2 countries); a line not understood keeps its message (tag
  sentora_unparsed). `elasticsearch/` the index template (data stream logs-sentora-default) and ILM policy.
  `kibana/build.py` writes `kibana/dashboards/*.json` (Kibana 9.5's dashboards API, PUT /api/dashboards/<id>): Overview,
  World map, Websites, Mail, Security, Panel and server; the workflow fails when the JSON differs from build.py. Made from a real
  panel's logs (the VM exercise on the Docker image) and looked at in Kibana 9.5.6 (screenshots).
- **Panel**: part of **Log Forwarder** (203; the owner, 2026-11-20: "build everything we need into the log forwarder
  module"; a separate Elk add-on existed on the branch only and was folded in). `addons/log_forwarder/code/elk.php`
  (class elk) and the page's administrators-only section "Elk node" (controller getElk, assets/elk.css). The panel's own
  certificate (/etc/sentora/elk, key root:<rsyslog's group> 640; also in the hidden setting elk_panel_cert, served at
  `modules/log_forwarder/code/node.php?cert=1` with no login). "Add the Elk node" (address + SE1 key) -> the daemon
  (elk::Prepare, before Log Forwarder's own run) connects with the panel's certificate, finds the authority whose code is
  the key, checks it signed the node's certificate (CN sentora-elk-node), pins it (elk_node_ca). Then **one** rsyslog
  file, Log Forwarder's `/etc/rsyslog.d/60-sentora-logforward.conf` (rsyslogd -N1 checked): each file followed ONCE (rsyslog
  follows a file in one imfile input only: a second input got nothing, tried 2026-11-20), its ruleset calls every
  Log Forwarder destination that wants it and `sentora_elk_out` (JSON lines, option.jsonf, gtls x509/name, 1 GB queue);
  plus the lines rsyslog gets from the journal by program (postfix*, sshd, sudo; Debian 13 writes no mail.log/auth.log).
  imfile is loaded only when no file read EARLIER loads it (an input before the load is an error). elk::After notes the
  state (queue, a check every 10 minutes or on Check now). rsyslog, its TLS driver and acl installed with
  package_install (the journal restarted so it hands rsyslog its lines); ACLs for rsyslog's user; an AppArmor rule file.
- **Uptime** (owner's list 1): Heartbeat (heartbeat-elastic, user sentora_heartbeat: write heartbeat-* + read for its
  state, ILM sentora-logs, password in Heartbeat's keystore) on the node. Log Forwarder 206: elk::SendSites (in After)
  sends one line, sentora_log "sites", message = JSON [{host, https}] (https = vh_ssl_tx has SSL; plus the panel's own
  address), when the list changes or hourly. Logstash's ruby filter validates it, writes /var/lib/logstash/sentora/
  sites.json and drops the event (never indexed). The timer sentora-elk-monitors (5 min) runs `sentora-elk-node
  monitors`: validates again (root does not trust the logstash-writable file), writes /etc/heartbeat/monitors.d/
  sentora-sites.yml (JSON, which YAML reads) only when changed; Heartbeat reloads monitors.d. Data view sentora-uptime;
  the Uptime dashboard. A check is up for 2xx/3xx/401/403/404 with a valid certificate. Note for the owner's LAN: the
  node checks by name, so a site behind his FortiGate needs the name to work from the node (hairpin or internal DNS).
- **Web firewall and Mail delivery dashboards** (2026-11-20, the owner's list 4 and 6): ModSecurity's lines in the websites'
  error logs (one per matched rule; 949110/959100 = the verdict: "Access denied" -> event.action waf-blocked, "Warning"
  -> waf-watched; others waf-rule with rule.id/name, sentora.waf.attack from the attack-* tag; 98xxxx = waf-report);
  spamd's "result:" lines (Log Forwarder 205 sends programname spamd/spamass* with the mail lines) -> sentora.mail.status
  spam/clean, spam_score, spam_rules; email.to/from.domain for every mail line. The failed-login figures exclude waf-*.
- **Server health** (2026-11-20, the owner's list 2; Log Forwarder 207): `code/health.php` (class elk_health), run by
  the daemon hook (every 5 minutes, not more often than every 4) while paired and the group "Server health" is ticked:
  CPU % and network bytes/s as averages since the run before (/proc/stat, /proc/net/dev, state in
  /var/lib/sentora/elk/health-last.json), load, memory/swap %, disk % and free for / and hosted_dir, Apache busy/idle
  and requests/s (`http://127.0.0.1/server-status?auto`), MySQL connections, queries/s, slow/min (SHOW GLOBAL STATUS),
  Postfix queue (files in deferred + active). One JSON line, kind "health", straight to 6514 (like the sites list);
  Logstash keeps only the known names, numbers only, into sentora.health.* (double in the template; an existing data
  stream maps them as float dynamically until it rolls over: both are numbers to Kibana). Dashboard "Server health",
  one line per panel. A saved group choice without the token "v2" (saved before 207) gets health too, unless "none".
  No systemd timer on purpose (a 5-minute daemon run is enough, no new unit or SELinux surface).
- **Alerts** (2026-11-20, the owner's list 3; Log Forwarder 208): the node's `sentora-elk-alerts` service (installers repo
  `elk-node/alerts/sentora-elk-alerts.py`, Python standard library only, own user sentora-elk-alerts, systemd-hardened,
  ES user sentora_alerts: read logs-sentora-* and heartbeat-* only, copies of its key/certs in /etc/sentora-elk-alerts)
  checks every 5 minutes: a website's last 3 checks down, a certificate under 14 days (outage under 3), disk >= 90%,
  mail queue >= 200, CPU >= 90% over 15 min, >= 100 failed logins or >= 200 waf-blocked in 15 min, >= 50 bounces in an
  hour, no line (health aside) from a panel for 30 min while there were lines that day. Limits in
  /etc/sentora-elk-alerts/limits.json; `sentora-elk-node alerts` checks now. Served on 6515 (firewall: panel-from, as
  6514) to the panel's certificate only; it sends /var/lib/sentora-elk-alerts/alerts.json and closes. Panel:
  `code/alerts.php` (elk_alerts) each daemon run while the group "Alerts" is ticked: ONE notice to the administrators
  (key elk-alerts, closable a year; outage when any is down; up to 12 titles, a link to the Alerts card,
  ./?module=log_forwarder&elk=alerts, and to Kibana). It is sent again (new version, shows after a close) only when a new
  alert id starts or it turns into an outage; otherwise its title/body are updated in x_notices without nt_updated_ts,
  so a closed notice stays closed (owner, 2026-11-22: "a bit much, and they come back when I close them"). The old
  one-per-alert notices (elk-alert-md5(id), 211 and older) are withdrawn once (state "one"). Choices in hidden x_settings
  elk_alert_rules: {"kinds": {kind: notice|off}, "mute": {id: {t, title}}} (kind = the id before ":"; Kinds() lists
  them by source; mute capped at 500); Alerts card on the page (Mute per alert, kinds as selects, Unmute). Mail: only
  kinds left at "Notice and mail", not muted; one mail for new ones, again after a day. The node's words are cleaned: no
  [ ], no http(s)://, no markup, cut to size; ids/boards by pattern. Node not updated: the page says to run
  sentora-elk-node update. Group token now "v3" (a saved choice without it gets Alerts too).
- **Website speed** (2026-11-20, the owner's list 5; core apache_admin hook + Log Forwarder 209): while the hidden setting
  apache_timing_log is 'true' (Log Forwarder sets it while paired with "Websites" ticked, and sets apache_changed), the
  core writes `GlobalLog "<log_dir>/domains/_timing/sites-access.log" "%{%Y-%m-%dT%H:%M:%S}t%{%z}t %v %>s %D %B %m \"%U\""`
  in httpd-vhosts.conf: every site in one file, the page without its query string. In domains/ (750, closed to the sites;
  `_timing` can be no account's name) and rotated by the domains' *access.log rule. Not in the access logs themselves:
  Webalizer, QuickFig and the import read those. Kind web-timing -> sentora.response_ms (double), event.duration,
  sentora.domain, url.path, status; dashboard "Website speed" (median, 95%, slow over 2 s, 5xx, slowest pages and sites).
  Needs the panel release with the core change; older cores ignore the setting.
- **Other sources** (owner, 2026-11-20/21: "definitely a fortinet dashboard ... what sites people are going to and
  also VPN or forticlient current users ... sales orders ... for our magento store ... office o365 and entra ...
  especially teams and mail ... a nice Veeam backup"; "other Sentora users may have a fortigate, so ... an option in
  the Elk node and Log Forwarder options"; search terms: "is it possible to add search terms people used on the site?").
  General options, each off until switched on; dashboards "Sentora: FortiGate: ..., Veeam backups, Microsoft 365,
  Online store" with their own links bar (data views sentora-fortigate, -veeam, -m365, -shop).
  - Node tool `elk-node/sources/sentora-elk-sources.sh` (installed as /usr/local/lib/sentora-elk/sources.sh, run by
    `sentora-elk-node fortigate|veeam|m365|sources`; `refresh` on --update). State in /etc/sentora-elk/sources/<name>
    (the address, or the tenant). /etc/sentora-elk-alerts/sources.json (on/off only) -> the alerts answer on 6515 ->
    the panel's "More sources" card.
  - FortiGate: FortiOS "reliable" syslog is length-framed (RFC 6587), which Logstash's tcp input does not read, so the
    node's rsyslog (rsyslog-gnutls, installed when needed) takes it: `/etc/rsyslog.d/40-sentora-sources.conf`, imtcp
    6516 gtls x509/name PermittedPeer sentora-fortigate (a client certificate the node signs: /root/sentora-fortigate.p12,
    password shown once; node CA /root/sentora-elk-ca.crt for the FortiGate), only from the given address (ruleset
    and ufw), JSON to Logstash pipeline `fortigate` on 127.0.0.1:5516 (`elk-node/company/fortigate.conf`), data stream
    logs-fortigate-default; an IPsec tunnel with no xauthuser and an address (or nothing) in user is
    fortigate.vpn.kind site-to-site, fortigate.vpn.name its vpntunnel, no user.name (the owner's MainToEast showed as
    "users" named by address); people: kind user (template `fortigate-template.json`: every pair in flattened fgt.*, ECS fields for boards).
    Tried locally on rsyslog 8.2504: the right certificate in, a wrong name and another authority cut off.
  - Veeam (12.1+, Options > Event Forwarding, TLS): rsyslog 6517 gtls anon (Veeam shows no certificate) + address
    only, with web.crt (it carries the node's addresses: Windows checks the address it connects to; node.crt only
    names sentora-elk-node, fine for the FortiGate, which checks the authority); pipeline `veeam` (5517, `company/veeam.conf`): RFC 5424 structured data -> flattened veeam.*, veeam_job.name,
    result, why, duration_min, transferred_gb; malware detection, console logins. Alert: a job whose last run failed.
  - Microsoft 365: `elk-node/sources/sentora-elk-reader.py`, service sentora-elk-reader (own user, hardened unit), ES
    user sentora_reader (create only on logs-m365-*); app secret in /etc/sentora-elk-reader/m365.json (root:reader
    640, asked for with read -s, never shown). Graph: signIns every 5 min (needs Entra ID P1/P2), Teams/mail daily and
    per-person reports every 6 h (CSV behind a 302 fetched without the token), open service issues; fixed ids so
    nothing counts twice. Permissions: AuditLog.Read.All, Reports.Read.All, ServiceHealth.Read.All (application,
    admin consent). The test uses `tests/elk/fakegraph.py` via SENTORA_TEST_* (never set for the service).
  - Online store (Log Forwarder 210, `code/shop.php`): the admin picks a website where Magento 2 is found
    (app/etc/env.php + bin/magento inside the owner's public_html). env.php is read as text; its database must be the
    owner's (x_mysql_databases) on localhost; read with the panel's connection: orders changed since last run (first
    run: a year, 500 a run; boundary ids remembered), their top-level items, Magento's search_query. Never names,
    e-mail, streets or payment. Sent as kinds shop-order/item/search over 6514; Logstash writes them to the index
    sentora-shop with a fixed id (an order re-sent with a new status replaces itself). Search words from the visits:
    q= of /catalogsearch/result/ and WordPress ?s= -> sentora.search.term. A long write needs the connection held
    open a moment before closing (Logstash otherwise loses the end: seen, fixed).
  - The owner's own (2026-11-21, all working): FortiGate 172.16.0.1 (lines arriving; 356 failed VPN logins in a day
    seen, advised MFA/blocking; the three "users" named by address were his site-to-site tunnels: fixed, now a tunnels table), Veeam over TLS (CA in Local Computer > Trusted Root), Microsoft 365 (695 sign-ins, reports, 8
    service issues; the app secret expires, then run the m365 command again), the store store.hemsaw.com (orders in).
  - FortiGate NAT (2026-11-21, done by the owner): the incoming WAN policy to the panel's Virtual IP (172.16.0.21)
    had NAT on, so Apache saw 172.16.0.1 for every visitor; the panel's gateway is 172.16.0.1, NAT switched off,
    his phone's address now shows. Older imported visits keep 172.16.0.1. The red error marks he once saw
    on the World map dashboard are gone (owner, 2026-11-21).
  - Six more dashboards (owner, 2026-11-21: "1-6 do all"): Today (sentora-today, first in both links bars; data view
    sentora-all = logs-sentora-*,logs-fortigate-*,logs-m365-*), Store funnel (Logstash sets sentora.store.step on
    web-access from Magento paths: search, cart-add, cart, checkout, order-placed, account-created; formulas per store;
    a calculated column can not sort a table), Attackers (sentora-all; BAD = failures, waf-*, IPS, bans, inside
    addresses left out; data_stream.dataset = where seen), Internet use (fortigate.hour "07" + hour_n integer for the
    heat map: a heat map ignores alphabetical order, a histogram keeps 0-23; fortigate.weekday "1 Mon"), Backup
    coverage (reader: Veeam REST 9419, POST /api/oauth2/token x-api-version 1.1-rev0, objectRestorePoints newest per
    name, jobs/states; PinnedHTTPS checks the certificate's SHA-256 before sending; `sentora-elk-node veeam-api ADDRESS
    USER` shows the fingerprint, asks "yes", then the password; veeam.json root:reader 640; read hourly; test stand-in
    tests/elk/fakeveeam.py). Alerts: attacker:<ip> (2+ datasets, >= 10 in an hour), office-travel:<user> (two countries
    within 3 h), office-new:<user-country> (not in their 30 days), backup-stale:<host> (> 26 h). Alert ids keep to
    [a-z-] before the colon (the panel's check): m365-... would be dropped.
    The owner updated his node and switched on veeam-api (2026-11-21): "all works".
  - Walls (owner, 2026-11-21, after a Veeam/Grafana blog: "colourful... green or red or yellow... without scrolling"):
    sentora-backupwall (now-2d, refresh 60 s) and sentora-serverwall (now-6h, 30 s), second and third in the link bars.
    Built from metric grids (breakdown_by + columns; colour = dynamic bands, build.py tile()/dial()/bands()); a grid
    keeps empty cells up to its limit, so limits are near real counts (jobs 15, machines 24, websites 18, repos 6) and
    the panels tall. Dials: gauge, styling.shape semi_circle, max static_value 100. Job health 1 / 0.5 / 0 (Success /
    Warning / Failed) on Veeam job events and on the reader's job states. Reader: repositories from
    /api/v1/backupInfrastructure/repositories/states (event.action repo-state, veeam_repo.*); alert repo-full:<repo>
    at >= 90% used (>= 97 outage). Kibana makes its own phone layout (panels stacked).
  - More walls (owner, 2026-11-21, "do all of them"): sentora-netwall (fortigate.vpn.up 1/0 set by the FortiGate pipeline:
    tunnel-up/tunnel-stats 1, tunnel-down 0; tiles by fortigate.vpn.name, last value), sentora-m365wall (reader: GET
    /v1.0/admin/serviceAnnouncement/healthOverviews, one doc per service per 10 min, m365.service/status/health 1|0.5|0
    /state in plain words; same ServiceHealth.Read.All permission), sentora-secwall, sentora-saleswall (now/d), sentora-week
    (now-7d). "Against last week": secondary metric time_shift 1w + compare {to: primary, palette: compare_to or
    compare_to_reversed (red when up)}. Certificates: runtime field sentora.cert_days on the sentora-uptime data view
    (not_after minus @timestamp, days). Charts: pie styling.donut_hole "m" (rings everywhere), treemap, area_stacked,
    date_histogram suggested_interval. Links: options use_time_range false (each board its own range).
  - Flows (sentora-flows): Vega Sankeys built by build.py sankey() (terms > terms > terms, %dashboard_context% clauses +
    %timefilter%; the layout done in Vega transforms: stack per column, gaps, bands as filled paths), written as
    dashboards/sentora-flow-*.vis.json, saved as visualizations, then put on the board by kibana/addvis.py (the
    dashboards API has no Vega panel). Labels take their bar's colour (readable in light and dark). Not done: ownCloud
    (owner, 2026-11-21: "forget the owncloud one for now").
  - Active Directory (owner, 2026-11-21: 3 DCs, Winlogbeat unzipped in C:\winlogbeat on each): `sentora-elk-node windows ADDRESS`
    (sources.sh) makes the DCs' own authority (sources/windows-ca.*, P-384, signs only DC certificates), a client certificate
    CN=sentora-dc-ADDRESS (key PKCS#8), adds ADDRESS to sources/windows, and /root/sentora-dc-ADDRESS.ps1 (600; built by
    sources/winsetup.py: finds winlogbeat.exe under C:\winlogbeat (or -Folder), writes the certs (key ACL SYSTEM +
    Administrators), winlogbeat.yml (Security log, 18 ids/ranges, drops computer accounts, network logons and good NTLM
    checks; add_locale for the office time), reinstalls the service with Winlogbeat's own script, test config/output,
    auditpol check). Logstash: company/windows.conf, beats input 0.0.0.0:6518 (node shows web.crt, client certs from the
    DC authority required), the gate drops any event whose certificate CN is not sentora-dc-<its own source address> in
    the list (SENTORA_DC_LIST written in by pipelines()); ufw: 6518 from each DC only. Fields: event.action (logon,
    logon-failed, kerberos-logon/-failed, ntlm-failed, locked-out, account-*, password-*, group-member-added/removed,
    gpo-changed, log-cleared, audit-policy-changed), windows.reason (status codes in words), windows.from, target, member,
    admin_group, logon_kind, hour_n, weekday, off_hours. Data stream logs-windows-*, data view sentora-windows. Tested
    locally with Logstash 9.5.6 + Filebeat (same protocol) and in the whole-way test (Filebeat on the runner, --network
    host = the allowed address); a cert for another address and one from another authority get nothing in. Alerts:
    ad-lockout, ad-admin (outage), ad-cleared (outage), ad-guess (>= 15 failures/15 min), ad-quiet (6 h).
  - vSphere (owner, 2026-11-21: "an esxi8 dashboard or vcenter"): `sentora-elk-node vsphere ADDRESS USER`: vCenter's
    certificate fetched, SHA-256 shown, "yes" needed; connects by the address if the certificate names it, else by its
    first DNS name pinned to the address in /etc/hosts (# sentora-vsphere). Metricbeat (apt, from Elastic's repo) runs
    as service sentora-vsphere, user sentora-vsphere, SSL_CERT_FILE=/etc/sentora-elk-vsphere/vcenter.pem (the vSphere
    module ignores ssl.* settings: found with VMware's vcsim; insecure: false), config root:sentora-vsphere 640,
    output to Logstash 127.0.0.1:5519 (company/vsphere.conf: vmw.kind/name/health/cpu_pct/mem_pct/used_pct/free_gb/on/
    alarms/snapshot_days; "on" = uptime or CPU > 0, no power state in Metricbeat). Data stream logs-vsphere-*, data view
    sentora-vsphere. The whole-way test runs vcsim (4 hosts, 12 VMs, 2 datastores). Alerts: vsphere-ds (90/97%),
    vsphere-host (red), vsphere-busy (90% over 15 min), vsphere-alarm (red), vsphere-snap (> 7 days), vsphere-quiet.
  - 4K TV (owner, 2026-11-21): the walls are at most 48 rows (fits a 4K screen at 150%); detailed boards may scroll.
  - Alerts added: a Veeam job's last run failed; >= 5 failed FortiGate admin logins or >= 20 failed VPN logins in 15 min.
  - `sentora-elk-node update` fetches the newest installer from master2027 first (bash -n and its "Checking" step must
    be there) into /usr/local/lib/sentora-elk/installer, then runs it (before 2026-11-21 it ran the install-time copy:
    the owner's update showed 11 steps and 6 dashboards; nodes from before then need the one-line fetch once).
  - Whole-way test: FortiGate (p12 opened with the shown password, 14 lines, a stranger's and no certificate refused),
    Veeam (7 events, the failed job's alert), Microsoft 365 (stand-in, 9 documents, the secret kept and unseen), the
    store (a fake Magento on the test panel: 85 documents, no e-mail or street on the node), search words.
- **World map** (the owner: "the world map that shows where your hits are coming from... something fancy"): a Kibana
  Maps saved object `sentora-world-map` (kibana/build.py writes dashboards/sentora-world-map.map.json): dark world
  (EMS dark_map), visitors as a heat map, attacks/failed logins/bans/probes as red circles with counts, countries
  shaded by visits; on the "Sentora: World map" dashboard with live figures and top countries (30 s refresh). The
  dashboards API takes no map panel: the installer adds it to that dashboard by reference (kibana/addmap.py).
- **Tested** (Actions > Elk node test, `.github/scripts/elk-node-test.sh`, whole way): the panel image + Log Forwarder
  (installed as zppy does), the node installed fetching the panel's real certificate and its code checked (a wrong code
  stops it), pairing (a wrong key: nothing sent), the panel's own logs arriving, 162 real lines of every kind all
  understood, a stranger's certificate and no certificate turned away, Kibana's login (can not delete the logs), the
  firewall lists, retention, password, --update keeping key/logins/logs. Tried by hand on a Debian 13 panel container
  against Elastic 9.5.6: pairing, rsyslog, live lines, one file shared by a Log Forwarder destination and the Elk node
  (both got the same lines); the page looked at light/dark, desktop/390 px; the dashboards and the world map looked at.
- Log Forwarder 204 (the owner: "have that script as an option in the module"): the card "Bring in older logs" (elk::QueueImport -> setting elk_import -> the daemon's elk::RunImports -> elk::Import; marks imported-<site> / import-failed-<site> in /var/lib/sentora/elk). The script below calls the same elk::Import.
- `elk-node/sentora-elk-import` (the owner's wish, 2026-11-20, for his web store): run as root ON THE PANEL,
  `php sentora-elk-import <site> [--days N] [--dry-run] [--again]`: sends what the panel still keeps of one site's access
  log (the current file, .1 and .2.gz: about three weeks; logrotate weekly, rotate 2) over the panel's certificate; each
  visit keeps its own date; lines from after the pairing time (already sent live) are left out; a mark in
  /var/lib/sentora/elk/imported-<site> stops a second run unless --again. Tried on the test panel.
- Later (ask first): the node on Debian/AlmaLinux/Fedora; the Docker and backup nodes sending too; per-client Kibana;
  Let's Encrypt for Kibana.

## Recent releases (newest first; every change is in CHANGELOG.md)

- **Released 2027.92 (2026-11-21, ordinary; the owner held it until the company sources were done):** the core's only changes are the Apache timing log (GlobalLog, only while apache_timing_log is 'true') and PHP builds looked for hourly while one is waiting; with it Log Forwarder 205-210 and the Elk node's health, alerts, website speed, other sources (FortiGate, Veeam, Microsoft 365) and online store (Elk node test: 0 FAIL). Last full test still 2027.91.
- **Released 2027.91 (2026-11-19, full test):** Matomo 5.14.3 (signed files checked; SENTORA-PATCHES.txt reapplied: the Ubuntu-PHP check now sits in `isPhpAffectedBySecurityAdvisory20261021()`, Matomo renamed it), published with Docker Hosting 141 / node agent 120 (real-node test passed on all six, alma10-vm with SELinux) and the Payment Plans MySQL/MariaDB tests (Add-ons workflow green).
- **Apps 107, QuickFig 210 (add-ons, 2026-11-13, after 2027.90):** the system PHP from php_cli (was /usr/bin/php<version>, Debian's name: on AlmaLinux a WordPress install stopped at its file check). Tried on the owner's AlmaLinux panel: WordPress installs Ready. Owner confirmed (2026-11-15): OpenCart installs too; his AlmaLinux panel on 2027.90 ran a full restore from the Restore tab to its end; his Ubuntu panel's PHP 8.1-8.4 updated to the Snuffleupagus builds; a second panel added to the same backup node. All fine.
- **Released 2027.90 (2026-11-13, ordinary; VM test passed just before on alma10, deb13, ub26.04, nothing refused):** `ctrl_system::Background()` starts the daemon's long jobs (offsite copy, restore, client FTP/zip backups, freshclam/sa-update, file scan) as their own systemd job (`systemd-run --collect`, unit sentora-job-*) when root on systemd, else setsid: a job started by a daemon run inside zsudo's service was killed when that service ended (owner's full restore stopped at its daemon run). Bars only while files are copied; a mistyped sentora-panel-restore prints usage.
- **Released 2027.89 (2026-11-13, ordinary; the VM test passed on alma10, deb13 and ub26.04 just before, 34-35 OK each, nothing refused):** off-server backup and restore under SELinux (`runcon` own domain for rsync and ssh-keygen: RSYNC/OWN/keygen in bin/sentora-backup-common; the daemon starts them through ctrl_system::OwnFiles), Backup Config followed in the background (code/progress.php, live bars), File scanning, sieve copy, Matomo trusted hosts, /opt/sentora-php relabelled, Payment Plans 108 (menu.php, Billing heading), dashboard spam widget by status file. The VM test's x_offsite (Back up now to an SSH user on the VM, Read the backups, restore a site); x_autoupdate now runs last (it installs the published release). Tried on the owner's AlmaLinux panel (2026-11-13, SELinux enforcing): Back up now to his backup node through the panel, the live bar worked, no refusals in the audit log; Read the backups listed the night. A restore of an account from the node, through the panel, also with no refusals (its bar showed the last copy's 100% during the next step: fixed on master2027 after 2027.89).
- **Releases: the owner said on 2026-11-13 "from here on out, just release when you think's best", and: say you are releasing, then wait at least 10 seconds before starting it** (still ask whether a release is a full test after many changes; last full test 2027.91).
- **Released 2027.88 (2026-11-13):** backup node pairing keeps the checked key in /root/.ssh/sentora_offsite_known_hosts (Ubuntu 26.04's OpenSSH PerSourcePenalties made the next scan come back empty), `pin_hostkey` scans ed25519 only and once more after 20 s; daemon-run note /var/lib/sentora/tmp/daemon-again (bin/daemon.php runs the regular hooks once more when a page asked during its run); DNS Config's BIND log (LogPath inside log_dir, LogCounts, ClearLines).
- **Released 2027.87 (2026-11-13, full test):** faster pages (db_driver::Remember, see Speed), Backup Config's destinations, node card and disk chart, PHP 8.5 cleanups, add-on sources in git (addons/).
- **Released 2027.86 (2026-11-13):** the backup node installed the node way (sys_nodes, see Sentora backup node > The node way), off-server backups only to a confirmed key, Docker Hosting 128-134 and node agent 113 (add-ons, already in Repo Browser).
- **Released 2027.81 (2026-11-12, full test):** **Keep the panel up to date** (Sentora Config > Updates, off by default; `bin/sentora-autoupdate` via zsudo `panel-autoupdate`, started by Panel Update's daemon hook at panel_autoupdate_hour once the release (by SHA-256, /var/lib/sentora-update/available-sum) has been seen for a day; checks daemon + login page, rolls back by itself, notices key panel-autoupdate + email; a release it put back is skipped; state /var/lib/sentora/panel-autoupdate.json) and **Keep add-on modules up to date** (addons_autoupdate, Repo Browser's daemon hook `RepoBrowserAutoUpdate`, notice key addons-autoupdate). sentora-update copies with tar (`copy_tree`), not rsync: rsync under a service is rsync_t under SELinux and failed; AlmaLinux panels need ONE update from SSH to get it. Log Forwarder 202 on every system (package_install, found paths, rsyslog's real user, SELinux port labels via semanage). Breadcrumb: module name links to its page, Refresh button. VM test exercises: x_update_all, x_logforward (receiver on 127.0.0.1:12514 with its own CA), x_autoupdate (update.conf URL emptied so it asks GitHub). All three systems passed 34/34, nothing refused.
- **Released 2027.80 (2026-11-12):** the VM test's fixes (see VM test above: fail2ban + nftables on Debian/Ubuntu, Debian cloud sources, OwnFiles, `>>` report, log viewers ask first, selinux_cil via sentora-update) and **Repo Browser's Update all** (owner's request: `doUpdateAll` takes inUpdateAll = comma list of repo:::module from the rows with `.has-update`, each checked like a single update, then one `update`; button `.rb-all` on the Updates tab only; VM test exercise x_update_all). Ordinary release (no full test), the owner's choice.
- **Released 2027.79 (2026-11-12): the widget rail** (owner's idea): `modules/dashboard/code/rail.php` (answers only with X-Requested-With: sentora-rail; makes a runtime_controller that knows only the session, filled with Closure::bind, because ctrl_users::GetUserDetail() reads the user through `$controller`), `assets/rail.js` (open/closed in localStorage `sentora-rail`; wide screens >= 1200px make room, narrower ones overlay; filled only while open, every 2 minutes; never on the dashboard), styles at the end of `assets/dashboard.css`, the aside + tab in Modern's master.ztml (THEMES.md says so). Smoke test step 4 (27 steps). Also: template tags without their module_controller::getX() show nothing and log a PHP warning (dryden/ui/templateparser.class.php HasGetter) instead of a fatal error.
- **Released 2027.78 (2026-11-12): kernel safety switches, the firewall and sentora-apparmor.** `bin/sentora-apparmor` (status, on): Sentora's paths into the profiles (from /etc/sentora/configs/apparmor/local or the release's platform-settings.json `apparmor_local`), AppArmor on, services restarted and checked, all undone on a problem; `--container` (test) loads with --config-file /dev/null -K and leaves the service alone; install test APPARMOR-TOOL. The server_monitor daemon hook writes `<sysctl_dir>/90-sentora-hardening.conf` (makes the folder on a minimal system) and applies it; a later-sorting file wins. `bin/sentora-firewall` (status, ports, on, off, allow, deny) drives ufw or firewalld (settings firewall_kind/cmd/service/package, set by the installers, filled on older servers): only Sentora's ports stay open (web, the panel's own apache_port/sentora_port, mail, DNS, FTP + passive range from the setting ftp_config_file, SSH on sshd's ports and the session's port); Sentora's ports can not be closed; read-only status in the hardening card (owner's choice: changes over SSH only). New installs switch it on (not in containers, unless one already runs there); existing servers: the administrator runs `sentora-firewall on`. Install test: switched on in each container and checked from outside (FIREWALL); the hourly jobs run on real traffic (HOURLY). **Released 2027.77 (2026-11-12):** Matomo's salt comes back with a full restore (its tokens are hashed with it); confirmed by the Docker restore test. **Released 2027.76 (2026-11-12):** hardening score, platform-settings fill, php_cli, Postfix moved to lmdb where hash/btree are missing (AlmaLinux 10), Matomo self-setup (the installers had run Matomo's console with /usr/bin/php8.x, missing on AlmaLinux/Fedora), postqueue no longer inherits the daemon's files (SELinux).
- **Released 2027.75 (2026-11-11):** **Problems log** (System Log Viewer, "Problems (mail, scanners, bans)"): `modules/system_logviewer/code/problems.php`, run by its daemon hook as root: Dovecot's error log, the journal's mail channel + clamd/freshclam/clamav-milter (priority <= warning, filtered again in PHP), /var/log/clamav, /var/log/sogo, fail2ban's Ban/Unban/ERROR lines -> /var/sentora/logs/problems.log (root:sentora 640). Sources FIXED in code (not x_settings: the panel can write its database). Drops password/secret/token/key and SMTP auth-failure lines; strips control chars; 400 chars, 400 lines a run, 1 MB + .1; writes only into a root-only folder, never through a link; state in /var/lib/sentora-problems (root 700). zsudo.log: ran/refused/dropped, fixed words only. Install test: an error and a password line written into dovecot.log, the first must appear, the second never. Also: Apache Status (server-status block written by the daemon for every system), SpamAV Admin + dashboard use the platform service names, Panel Update's daemon hook sets up zsudo's queue on servers updated by an older sentora-update, the post-install check waits for a busy daemon run. **Hardening score (released in 2027.76, owner's idea from Lynis; Lynis itself is not run):** our own checks added to sentora-post-install-check (`--json` report, score OK 1 / WARN 0.5 / FAIL 0, history of 90 in hardening-history.json); root runs it hourly via zsudo `hardening-check` (server_monitor daemon hook, SentoraHardeningQueue) and from the card's "Run the check now"; Server Monitor's card (`assets/hardening.js`, feed `code/hardening.php`, admins only) shows gauge, history, areas, issues with fixes. The install test requires the report. **Platform settings for older servers (2027.76):** (also php_cli, Postfix lmdb, Matomo self-setup) a new x_settings platform setting reaches servers installed before through `platform-settings.json` (installers release, built by `.github/scripts/platform-settings.sh` from each installer's Configuring Sentora step) and the core's `bin/sentora-platform-fill`, run by sentora-update: only settings still at the default change. So a new setting only needs its line in the installers' Configuring step. Install test checks it (PLATFORM).
- **Released 2027.74 (2026-11-11):** **zsudo is a queue now, not set-uid** (the owner's AlmaLinux VM: Panel Update said "zsudo answered 1": SELinux keeps the panel in httpd_t, which may not queue at(1) jobs). `bin/zsudo.c`: `zsudo <action>` checks the fixed list and writes /var/lib/sentora/zsudo/new -> queue (panel user only, 700); `sentora-zsudo.path` starts `sentora-zsudo.service` (ExecStart via /bin/sh so SELinux runs it as an ordinary service), root runs `zsudo --run-queue`: checks again, deletes, runs. `bin/sentora-zsudo-setup` builds and sets up (installers; sentora-update when the source changed, zsudo is still set-uid, or the watcher is not running; it never restarts the .service, which may be running the update). The install test queues daemon-run as the panel user (all five: run within 4 s). Tried under SELinux enforcing on the owner's AlmaLinux 10.2 VM (2027.69 -> 2027.74): Panel Update's Check now runs through the queue and the page reads the status ("no updates available"); that VM needed `bash /etc/sentora/panel/bin/sentora-zsudo-setup` once, because the OLD sentora-update built the new zsudo set-uid with no queue: Panel Update's daemon hook now does that setup by itself (after 2027.74). Apache Status said 404 on AlmaLinux/Fedora (their mod_status has no /server-status block): the apache_admin daemon hook now writes it for every system (after 2027.74); the install test checks it. Also the Docker image and the Docker Hosting add-on.
- **Released 2027.73 (2026-11-11):** Sentora2027Setup.exe (Inno Setup, `windows/sentora2027-setup.iss` in the installers repo, built by
Actions > Windows setup on every release; `test_release` puts a test build on the `windows-setup-test` release for a public link):
Sentora 2027 in Settings > Apps and the Start menu, the same PowerShell script inside (-FromSetup, -Yes), updates in place (fixed AppId),
uninstalls cleanly (.wslconfig put back). Tested on the owner's Windows 11 VM. **Website:** SentoraReloaded2027/SentoraReloaded2027.github.io
(https://sentorareloaded2027.github.io, index.html + topic pages made by tools/build-pages.py, on `main`, GitHub Pages; never
edit a topic page by hand, change build-pages.py and run it); only tested facts on it. 2026-11-21: /kibana-dashboards/ (the Elk
node's 34 dashboards, screenshots from a sandbox with sample data only, neutral names) and /nodes/; main page sections #dashboards, #nodes. Its "Find an answer" search reads the READMEs and
CHANGELOG live from raw.githubusercontent. **Ask Sentora** (ready, off): `worker/ask-sentora` in that repo, a Cloudflare Worker answering
with Claude from those documents (cached block, refusal fallback, origin and per-visitor limits; 17 checks in its test; deploy: Actions >
Deploy Ask Sentora). Waits for the owner's Anthropic API key (with a spending limit) and Cloudflare account; then set `ASK_URL` in index.html.
- **Released 2027.72 (2026-11-11):** Windows (WSL) fixes from the owner's first run, login block, double-click Status/Backup/Uninstall,
Sentora Panel desktop shortcut, tray icon (green while port 443 answers, one click opens the panel), no disk alerts for read-only disks.
- **Released 2027.71 (2026-11-11):** AppArmor on for Debian and Ubuntu (see SELinux/AppArmor above).
- **Released 2027.70 (2026-11-10):** Fedora 45, the WSL script and ModSecurity are on the `current` release; all five
systems passed the install test (with the ModSecurity attack test, which uses the smoke test's kept site).

## Add-on sources (2026-11-13, owner's yes)

Each add-on's files are in sentora-installers-2027's `addons/<name>/` (unpacked from the .zpp files of 2026-11-13, so the
contents are exactly what panels had). `addons/build.sh <name>` builds sentora-repo/<name>.zpp: module.xml and
module.zpm first, then every folder and file sorted by name, all dated 2026-01-01 UTC, folders 755, files 644 (755 where
git has them executable), `zip -X`; so the same files give the same bytes on any computer (checked: two copies with
other dates and another time zone gave the same SHA-256 for all nine). It also sets <latestversion> and the
SHA256SUMS line (sorted by name). `addons/check.sh` compares every .zpp with its folder (diff -r of the unzipped zip),
version and checksum; the **Add-ons** workflow (.github/workflows/addons.yml) runs it, builds all again and fails if a
zip differs, and runs tests/docker_hosting, payment_plans and php_hardening on their folders. All nine zips were built
again with it (same contents, same versions: panels see no update; only SHA256SUMS changed). The panel's own unzip
(sys_archive::Unzip) gives exactly the folder; the three test suites passed on built zips (447, 218, 218).

**OPcache and add-on updates (2026-11-22):** the .zpp files carry one fixed date (2026-01-01), and ZipArchive keeps it, so
an upgraded file had the same date as the one before and PHP-FPM's OPcache kept the old code until restarted (the owner's
Log Forwarder 211). bin/zppy's zppy_fresh() now touches every file of the module after unpacking (install and upgrade).
Until a panel has that core, after an add-on update: restart its PHP-FPM services (the panel's and sentora-php*-fpm).

**Store searches sorted; orders with their lines (2026-11-22, owner's screenshots):** sentora.conf has one rule (tc lambda,
in the shop filter and the web filter): length <= 2 -> noise; starts with "by model" or "view id" -> old (the owner's old
Magento addresses, turned into searches by the store); WordPress and scanner words (wp, xmlrpc, admin, well known, api
session, hellopress...), hex codes of 10+ characters, .php/.env... -> noise; else customer. Fields: sentora.shop.term_class
(shop-template) and sentora.search.class (index-template). build.py: SEARCHED (visits: persons, not noise/old, plus a
name fallback for visits stored before), STORE_SEARCH, STORE_OLD, SINCE_START ("Times (since the store began)": Magento's
popularity is cumulative). New Online store row "Old addresses still being asked for". Sales wall "Latest orders and what
was in them": item docs with sentora.shop.number (Log Forwarder 213 sends increment_id on each item). shop.php resends
orders (the last year) and the search list once (state "resent" = 213; counts reset), so stored docs are re-sorted and
numbered (same doc ids: replaced, not doubled). Checked in a real Logstash 9.5 and Kibana 9.5 (both boards load, no
panel errors, screenshots); the Elk test's fake store has a bot probe and an old address (7 searches, 87 docs).

**AD: Windows' own accounts (2026-11-22, owner's screenshot):** the "Remote desktop and keyboard logons" table showed
dwm-1, umfd-0... (Window Manager / Font Driver Host virtual accounts, one per session, logon type 2). windows.conf sets
windows.system_account (boolean, in windows-template.json) on logon events: DWM-n, UMFD-n, names ending in $, ANONYMOUS
LOGON, LOCAL/NETWORK SERVICE, SYSTEM, IUSR, "-", or domains Window Manager, Font Driver Host, NT AUTHORITY, NT SERVICE.
build.py's AD_PEOPLE (also by name dwm-*/umfd-* for older events) is added to AD_LOGON and the RDP/keyboard table. Test
event: DWM-1 in tests/elk/winevents.py (14 events now; both Elk tests count 14 and check the mark).

**Elk node on five systems (2026-11-22, owner: "the elk node definitely should support all 6"):** the installer detects
ubuntu 26.04 / debian 13 (deb) and almalinux, rocky, rhel 10 / fedora 45 (rpm); $CONF/family and $CONF/firewall
(ufw | firewalld rich rules); the first tools (openssl, curl) installed before the panel's certificate is read; SELinux
port labels for 6516, 6517, 5516, 5517. The Elk test (Actions > Elk node test, matrix of the five) passed on all five at
ad37c8f; the VM test (elk-vm-test.yml, AlmaLinux 10.3, SELinux enforcing) passed at ea5f042: 18 ok, no AVC. Test-only: the AlmaLinux and Rocky containers set rsyslog's global(libcapng.enable="off"), because RHEL 10's
libcap-ng does not know the runner kernel's newer capabilities (capng_apply=-4); real servers set nothing (the VM
test, a real kernel, needs nothing). Still to do: the website's and Log Forwarder's page's "Ubuntu 26.04" for the node.

**Elk node website checks: DNS and where (2026-11-22, owner's request):** the owner's internal (.local) websites showed
"down" ("lookup X on 127.0.0.53: server misbehaving": systemd-resolved does not pass .local to unicast DNS) and the
public ones timed out (no hairpin NAT to the office's own public address). elk-node/sources/monitors.py (installed to
/usr/local/lib/sentora-elk/, run by `sentora-elk-node monitors`, the 5-minute timer) now writes, besides
monitors.d/sentora-sites.yml, /etc/sentora-elk/heartbeat/{resolv.conf,hosts,at,not-in-dns}; Heartbeat's unit drop-in
sentora-net.conf binds resolv.conf and hosts over /etc/resolv.conf and /etc/hosts (BindReadOnlyPaths with "-", files
written in place, the first run restarts Heartbeat). DNS: $C/dns (auto | panel | up to 3 servers), else
/run/systemd/resolve/resolv.conf (the real servers), else /etc/resolv.conf. Where: $C/sites-at (auto: the panel's
address from panel-url when it is RFC 1918 / 100.64/10 / fc00::/7; panel; dns; an address). Left out: a site every
answering DNS (Heartbeat's servers, plus the panel's own DNS when checks go to the panel) calls NXDOMAIN/no A and AAAA,
unless it is in the node's /etc/hosts; an error or no answer keeps it (owner: some sites are set up but not in DNS yet;
some use the panel's DNS, not the office's). Tested with stand-in DNS servers (office, resolved-like errors for .local,
silent) and in the Elk test (left out, then named in /etc/hosts; Heartbeat alone sees its hosts).

**Notice cookies (2026-11-22):** dryden/ui/tpl/notice.class.php kept one cookie per closed notice and version
(`sn_notice_<id>-<updated>`, a year), so Elk alerts (closed again after each change) filled the browser until Apache's
8 KB header limit refused every page with 431 (the owner's panel). Now two cookies: `sn_nc` (a year) and `sn_ncl` (session,
'closable' => 'login'), keys joined with dots, each cut on every page to the notices still shown; old `sn_notice_*` are read
once and removed. Tested in Chromium (old cookies taken over, close, reload, a notice gone). A browser already refused
has to delete the panel's cookies once.

**PHP 8.5 (found by the Add-ons workflow, 2026-11-13):** GitHub's runners have PHP 8.5, like the panels on Ubuntu 26.04 and Fedora 45. It flags curl_close(), imagedestroy(), finfo_close() and xml_parser_free() as deprecated (they do nothing since PHP 8.0/8.1); they are gone from the core, the add-ons (Docker Hosting 135, Payment Plans 107, QuickFig 209) and node agent 114, and only called on PHP < 8 by the feedback server (its host's PHP is not ours). PHP 8.5 also adds a stack trace to fatal errors (Web Hardening's test now ignores it). Do not use them again.

**Speed (2026-11-13, the owner asked to look; Actions > Speed test, `.github/scripts/speed-test.sh` in the installers repository):** a fresh panel, every page timed 5 times with its database queries (MySQL's general log as a table), the panel's PHP (OPcache), how styles and scripts are sent, the database server's numbers. Found ~500 queries a page (logmode 113x: `ui_templateparser::CompileFunctions` runs the hooks OnBefore/AfterTemplateProcessor for every tag, and `runtime_hook::Execute` read logmode and globbed every module each time; GetUserDetail ~150x; a permission query per module). Now `db_driver::Remember($key, $read)` keeps what a page reads (web requests only; `db_driver::Changed()` empties it on any write: `db_statement` (PDO::ATTR_STATEMENT_CLASS) and query()/exec() of anything but SELECT/SHOW/DESCRIBE/EXPLAIN/WITH), used by `ctrl_options::GetSystemOption` (all settings in one query), `ctrl_users::GetUserDetail`, `ctrl_groups::CheckGroupModulePermissions`, `ui_moduleloader::Modules`; `runtime_hook::Execute` remembers which hooks have files (web only). ~120 queries, ~30% faster. `ui_assets::Versioned` (index.php's output filter) adds ?v=<mtime> to etc/styles and modules/*/assets .css/.js; Apache Admin's `SentoraPanelAssets()` gives those a year's cache (immutable, private: no "public") only for plain file endings (css, js, images, fonts; never a .php under assets/, Docker Hosting's widget.php is one), others no-cache; compression (`SentoraCompression()`) on the panel's vhosts only for its styles, scripts, SVG and fonts: everything else gets no-gzip/no-brotli (SetEnvIfExpr), also against Debian/Ubuntu's global deflate.conf, because a compressed page with its CSRF token next to attacker text leaks over HTTPS (BREACH). Checked with a real Apache 2.4 (page: not compressed; css?v=: br + year; w.php?v=1: neither). Database: nothing to tune (everything in the buffer pool). Left: repo_browser runs CREATE TABLE IF NOT EXISTS 23 times a page (each one also empties the page's memory).

## SpamAV Admin: File scanning (2026-11-13, owner's request)

`dryden/sys/filescan.class.php` (sys_filescan) + `bin/sentora-file-scan` (root; --changed | --full; flock /var/lib/sentora/filescan.lock, nice 19, ionice idle). Files of hosted_dir/<account>/ (backups/ only with fs_backups), RecursiveDirectoryIterator without links, size <= min(fs_max_file_size, clam_max_scan_size), the opened file's inode must match the listed one; contents to clamd's socket (sys_platform clamd_socket) with INSTREAM: clamd needs no file access (no antivirus_can_scan_system). Changed = max(mtime, ctime) >= last_scan_started. Results /var/sentora/logs/filescan.json (root:sentora 0640: runs (60), findings (500), running, last_full, last_auto). Quarantine /var/sentora/quarantine (root 0700): <16 hex id> mode 000 + <id>.json (path, uid, gid, mode); Release() puts back only into the same existing folder under hosted_dir, never over something. Notices: owner (fs_notify_owner, key filescan-YYYYMMDD) and group 1 (key filescan-admin, a week). Settings in sys_spamav::Definitions section 'filescan' (fs_enabled, fs_hour, fs_full_day, fs_max_file_size, fs_backups, fs_quarantine, fs_notify_owner). spamav_admin's daemon hook calls sys_filescan::Daemon() (page requests in /var/lib/sentora/tmp/filescan-request.json; the nightly start at fs_hour, once a day; full on fs_full_day or the first scan). Page: tab "files" (getFileScan, getFileForm, doFileRequest with zsudo daemon-run), getFileSummary on the Overview. Needs clam_enabled (clamd running). Tested against a real clamd here (custom .ndb/.hdb signatures), 17 checks; screenshots of 3 states.

## Planned (the owner said yes; do when no other session is changing add-ons)

- (done 2026-11-15, owner's yes) the hand-over shims `node/` and `backup/` were removed from the installers repository: the installers live only in `docker-node/` and `backup-node/` (panels before 2027.86 and Docker Hosting before 130 showed the old addresses).

## Waiting on the owner (do not start without asking)

- **gVisor on SELinux nodes** (owner 2026-11-18: later): write Sentora's own SELinux rules for gVisor (a domain for runsc, tried on the Docker node test's SELinux machine) so apps on AlmaLinux/Rocky/RHEL/Fedora nodes get gVisor and SELinux together. Until then gVisor is not used there (agent `Runsc()`).

**Native Windows panel** (owner's goal, 2026-11-23: "restoring the Windows part makes it how it was supposed to be all
along"; ZPanel ran natively on Windows until it was dropped, and that was its real difference): the same panel code on
Windows, platform differences in settings and per-program drivers (as ZPanel's mailserver_php / ftp_php drivers:
hmail.php / postfix.php, filezilla.php / proftpd-mysql.php; Sentora 2027's hidden x_settings already do this for the
Linux systems). WSL stays the supported Windows way until then. Groundwork so far, in its own repository
**RusTus/sentora-windows-2027** (private; installer/, CLAUDE.md, install-test.yml on every push): a PowerShell stack installer for Windows Server 2022/2025 into C:\zpanel (Apache 2.4 Apache Lounge, PHP
8.5 TS, MySQL 8.4 LTS, checksums pinned, services Apache2 / MySQL, -RelaxedSqlMode, -LegacyForeignKeys), tested on
GitHub's Windows Server 2025; SFTPGo for FTP (users in MySQL, FTPS required, SFTP 2022, REST API on 127.0.0.1,
C:\zpanel\bin\ftpuser.ps1). It asks about each part, Yes by default (owner: "I'm going to want the default installation
every time"); -Unattended takes every default, a switch answers its own question. The owner's download page is a private
artifact (a zip; .ps1 downloads are not allowed there). Picks so far: FTP SFTPGo (FileZilla Server and IIS FTP considered); mail Stalwart (SQL directory) unless
hMailServer 5.8 is released (commits Nov 2026); DNS Technitium or CoreDNS (zone files); cron Task Scheduler; webmail
Roundcube. Mail is done (2026-11-23, tested on GitHub's Windows Server 2025): Stalwart 1.1.1 under NSSM (service
Stalwart; RocksDB in C:\zpanel\data\mail; set up through its JMAP API in recovery mode on 8091 so no mail port opens
before its settings are in: listeners 25/587/465/143/993/995 and https on 127.0.0.1:8443 only, its log, 127.0.0.1 and ::1
never banned, the shared certificate C:\zpanel\configs\ssl\server.crt/key, the domain and an admin in
configs\stalwart\admin.txt; each run does it again without duplicates), Roundcube 1.7.6 (/webmail, database roundcube),
and ActiveSync with Z-Push 2.7.7 (/Microsoft-Server-ActiveSync; PECL php_imap 1.0.3; AWL 0.65 for CalDAV; an
IpcFileProvider written for Windows, as Z-Push's IPC needs sem_get or WinCache; combined backend IMAP + CalDAV + CardDAV;
LOG_SYSLOG_FACILITY LOG_USER). Apache gets HTTPS on 443 (mail.conf: proxy /dav and /.well-known/cal|carddav to
Stalwart; plain HTTP to those addresses redirects). Mailboxes: C:\zpanel\bin\mailuser.ps1 (the panel's way in later,
same API). SOGo is Linux-only, hence Z-Push. ClamAV 1.5.5 (2026-11-23): services ClamAV (clamd 127.0.0.1:3310) and FreshClam under NSSM
(bin\nssm), Stalwart's MTA hook (stage data) to http://127.0.0.1/_sentora/mailscan (apps\mailscan\scan.php, Require ip
127.0.0.1, key in configs\stalwart\mailscan-key.txt): virus refused 554, ClamAV down 451; EICAR tested. SpamAssassin left
out (Stalwart's own filter does that job; the owner agreed). DNS is done (2026-11-23): CoreDNS 1.15.0 (service CoreDNS under NSSM; Corefile in configs\dns: the auto plugin
over configs\dns\zones\db.DOMAIN, reload 15s, any, errors; no forward, so other names are REFUSED), dnszone.ps1 (zones and
records, values checked, serial raised; the panel's DNS driver on Windows can write the same BIND-format files). Not installed
next to Windows' DNS Server; something on 53 at one inside address (connection sharing, Docker) is fine, CoreDNS listens on
::. Releases (2026-11-23): VERSION 2027.N in sentora-windows-2027 and a
release.yml run by hand (the whole install test first, then v2027.N and "current", whose link never changes:
releases/download/current/install-zpanel-stack.zip; the installer prints its version). 2027.1 released; the owner's
hand-made release tagged "main" (the branch's name) was removed by it. 2027.2: ClamAV's first download tried three times, and
the install goes on without the virus check if it still fails (on the owner's fresh Server 2025 the first download failed
once with "Connection failed", then worked). Owner's own test on a real Server 2025 (2026-11-23): installed, mailbox, DNS MX,
webmail sign-in and a message sent and received: all working. 2027.3: updates. C:\zpanel\bin\sentora-update.ps1 (-Check:
tells only, logs\update-available.txt and the Application log; a nightly task "Sentora update check" at 3:17 runs it; without
-Check: current release downloaded through the GitHub API, SHA256SUMS checked, configs zipped to C:\zpanel\backups, the NSSM
services stopped and their programs waited for, then the new installer run with -Unattended and the answers in
configs\install-choices.json; configs\version.txt holds the version). Private repository: a read-only fine-grained token in
configs\update\github-token.txt. The test updates from the checked-out copy to current and checks mail, zones and services.
Expand-Into now waits for programs running from a folder before replacing it (NSSM says stopped before its program exits). Still open: a real certificate (Let's Encrypt) and
Autodiscover; then scheduled jobs, the update command, the panel. Next when
the owner says: finish the stack (mail, DNS, cron), a Windows update script (a version list with
checksums, compared nightly, notify first), then Windows drivers module by module, FTP first.

**New node types** (owner's ideas, 2026-11-15, back burner; the failover/standby panel left out on purpose):
- **Edge node**, one node with roles switched on: Secondary DNS (BIND secondary of the panel's zones, signed transfers), Watch (outside checks of sites, mail, certificates, the panel; email/push when the panel itself is down; uptime per site), Backup MX (accepts and holds mail while the panel is down), Outgoing mail relay (optional: the panel's mail leaves from the node's IP). 1 CPU / 1 GB; one pairing, one status card with a row per role; designed so two Edge nodes in two places can run side by side.
- Kept as their own nodes (not in the Edge node): **Database node** (MySQL for heavy sites), **Storage node** (S3-style storage for apps), **Edge cache** (CDN-like proxy in front of sites). The backup node and Docker nodes stay single-purpose.

**PostgreSQL for clients** (owner's idea, 2026-11-13, back burner): an add-on like MySQL Databases (client databases and users under their prefix, counted in the package's database limit; the panel itself stays on MySQL). Needs: PostgreSQL from each system's packages via x_settings, localhost only; one role per client, no superuser; pgsql in the PHP bundles (not built today) and the system PHP's package; PHP-Adminer for web access (it speaks PostgreSQL); client backups, the off-server copy and restore to include pg_dump; size watched by the daemon (PostgreSQL has no quota). Several MySQL versions side by side: not advised.

**A module's menu name per kind of user** (owner's idea, 2026-11-13, "in the future"): a module could show another name in the menu depending on who looks (Payment Plans: "Billing" for a billed client, "Payment Plans" for sellers), much like code/menu.php decides whether it shows at all (ui_moduleloader::Shown). For now Payment Plans keeps its one name; only its page heading differs.

**zGod-style overview** (owner's idea, 2026-11-12, remind him now and then): his old zPanel module zGod let the administrator see every account under zadmin as if it were zadmin's own client: totals and "who uses the most" for disk, traffic, mailboxes and their sizes, quotas (figures only, nothing private). Would be an admin-only page or add-on built on the usage figures the panel already keeps; he may hand over the old module to show what it did (complete rewrite). Matomo's clean-up of old raw visits (delete_logs) is also on hold until he decides. Hosting for the add-on repository and the feedback server was deferred by the owner.
