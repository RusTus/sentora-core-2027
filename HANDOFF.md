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

## Payment Plans (add-on, sentora-repo/payment_plans.zpp, 112)

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
- **109** (owner, 2026-11-23: "is it 100% ready for a real panel?"; an independent review, then fixes): `pp_pay::Create` claims the
  invoice first (`UPDATE ... SET in_order_in = now WHERE in_order_in IS NULL OR <= now-10`, one row or "started a moment ago")
  and stores its reference only while the claim is still its own (else it abandons the page it made); `Apply` failed/pending
  only `AND in_order_vc = <the answer's ref>`; the controller's `CloseOnline` (Settle, Abandon, Settle) runs before Void and
  Mark paid; `Abandon` without keys: a processor whose page takes money alone (`TakesMoneyAlone()`: Stripe true, PayPal
  false; absent = true) is not called closed for a day after the start; `x_pp_plans.pl_anchor_in` (day of the paid-until date
  at StartPlan; 0 for older plans = as before) given to `PeriodEnd`; PayPal disputes: lost only for RESOLVED_BUYER_FAVOUR,
  RESOLVED_WITH_PAYOUT, ACCEPTED; `Tell` logs `mailfail` when sys_email::SendEmail returns false; step 3 skips deleted
  accounts and sets in_reminded_in = 2 only for an active plan. Phone: .pp-table rows become cards.
- **110** (owner: "go ahead with 1-8"; 110-112 planned, 8 waits for the owner's sandbox keys): doStartAll in test mode needs
  inTestOk=1 (a tick on the page); x_pp_sellers.ps_resume_vc today|owed (added as 'owed' for sellers from before) used by
  MarkPaid when the plan was switched off and paid-until is in the past (paid-until = now, anchor = today's day);
  ps_before_in (0-30; 0 for sellers from before) + x_pp_invoices.in_soon_in: daemon step 2b Tell('soon'); doExport (CSV,
  UTF-8 BOM, formula-looking cells prefixed with ', the seller's invoices only). Settings not posted keep their value.
- **111**: x_pp_billto (client's company, address, tax ID; doBillTo from the client's page) copied into the invoice's buyer
  text by BuyerText() when it is made; x_pp_extras (lines waiting for the next hosting invoice; doCharge, inDropExtra) and
  x_pp_lines (an invoice's lines). MakeInvoice and MakeOneOff both go through Issue(); a one-off has in_period_vc 'once':
  MarkPaid leaves paid-until alone for it and the "already has an open invoice" checks skip it; unpaid past the grace days
  it switches off like any invoice, and paying switches on only when no other invoice is overdue. Pages: seller card "Extra charges and one-off
  invoices", client card "Your billing details", invoice view with lines and subtotal (pp.css .pp-block/.pp-chargeline).
- **112**: code/pdf.php (pp_pdf: plain PHP PDF writer, A4, Helvetica WinAnsi with its width table, iconv to Windows-1252,
  more pages when long; Invoice() and CreditNote()); doPdf (POST, seller or client only); Tell attaches the PDF to 'new'
  and 'paid' mails when sys_email has addStringAttachment. x_pp_credits + x_pp_invoices.in_credited_in: pp::MakeCredit
  (under the seller's lock, CN-/TEST-CN- numbers from ps_cnnext_in/ps_cntestnext_in, never past the total, the last one
  takes the rest of the tax), called by pp_pay::Reversals for a refund amount above what is credited and for a lost
  chargeback, and by doCredit (seller; for an invoice not paid online it also sets in_refund_vc/in_refunded_in).
  Tax: ps_taxincl_in (in_items_in = what the lines add up to as shown; in_taxincl_in), ps_rates_tx ("DE 19" lines,
  pp::Rates), ps_country_vc + bt_country_vc, ps_reverse_in (TaxFor: rate 0 and in_note_vc when the client has a tax
  ID and another country; with inclusive prices each line is taken without that country's rate). The settings form
  sends inTaxOpts so an older form keeps the options. pp::Plain writes negatives with one sign.
  **Still open:** a real PayPal/Stripe sandbox run with the owner's keys (PayPal `payment_source.paypal.experience_context`
  and a newer Stripe-Version wait for it); invoice filter/paging when a seller's list gets long; VAT ID check (VIES) by
  the panel (the seller checks for now).

## Web Hardening (add-on, sentora-repo/php_hardening.zpp, 106, administrators; folder php_hardening, was "PHP Hardening")

two guards, each off / watch only / on. **ModSecurity:** the installers install it switched off (settings modsec_*; the core's `etc/modsecurity/coreruleset` is OWASP CRS 4.25.2 LTS, updated with `.github/scripts/update-crs.sh`; Sentora's httpd.conf includes modsec_conf_dir); the add-on's daemon writes `sentora-web-hardening.conf` there (`ph_modsec::Conf`), tests Apache, reloads, rolls back; the panel's own host is always DetectionOnly (106: ModSecurity 2.9.8+ refuses form data over SecRequestBodyNoFilesLimit even in DetectionOnly, so watch only uses 12.5 MB and the panel's hosts skip bodies over 128 KB); what it caught comes from the audit log (parts AHZ). The install test switches it on through the add-on and requires 403s for attacks (`.github/scripts/modsec-test.sh`). **Snuffleupagus:** the PHP bundles carry it switched off; Actions > PHP bundles also builds it for each system's own PHP (on the release, in php-bundles.json under `snuffleupagus`); rules in `ph::Catalog()`; the daemon writes 90-sentora-php-hardening.ini and the rules into that PHP's own scan dir, tries PHP with them first, reloads, puts back on failure; what it caught comes from `journalctl -t snuffleupagus`. Not used: read-only-exec (sites own their files) and anything needing a secret key (the rules file is world-readable). Tried on the owner's AlmaLinux panel (2026-11-12, both guards, checked out OK); the ModSecurity part is also tested by the install test; Snuffleupagus builds for every system's own PHP are on the `current` release (alma10 8.3, debian13 8.4, ubuntu26.04 and fedora45 8.5; checked 2026-11-11), so it can be tried now. What it caught (102-103): `assets/ph.js` + `code/caught.php` (module_controller::Feed: list pages before/after an id with filters q/kind/act/guard via ph::Where, or stats=1&days via ph::Stats); every line has ca_kind_vc (ph::KindOf: CRS families / Snuffleupagus feature), daily counts in x_ph_daily (400 days), list keeps 5000; Look now = zsudo daemon-run once a minute. The panel's own names (ph_modsec::PanelHosts: sentora_domain + Branding addresses) and requests to a bare IP are DetectionOnly. ModSecurity 2 writes zones as "--0600" (Entries fixes it). Tests: `tests/php_hardening/run.sh` (real Snuffleupagus, fake PHP-FPMs and Apache).
- **107 Bans and Flood protection** (owner, 2026-11-23: "mod evasive" and a way to see and change fail2ban's bans; the
  owner chose Web Hardening over a page of its own). `code/bans.php` (ph_bans): hidden settings fail2ban_client and
  fail2ban_dir (added by the add-on with /usr/bin/fail2ban-client, /etc/fail2ban when missing). The daemon (as root)
  writes /etc/fail2ban/jail.d/sentora-web-hardening.local ([DEFAULT] ignoreip = the server's own from jail.d/sentora.conf +
  the never-ban list; [sentora-manual] systemd backend, a filter that never matches, bantime -1, banaction_allports;
  [sentora-flood] polling on log_dir/domains/*/*-access.log tail, maxretry = limit, findtime 60) and filter.d/sentora-flood.conf
  (`^<HOST> -[^"]*"[A-Z]+ `, static files in ignoreregex: fail2ban takes the date out before matching, so no `\[...\]`),
  tests with `fail2ban-client -t` (old files back on failure), reloads when they changed or the list of website logs did
  (a jail with no log stops fail2ban: the flood jail stays disabled until one website has a log), then does the page's
  requests (x_ph_settings ban_asked; ban = set sentora-manual banip, unban = unban, out of every jail), unbans hand bans
  whose time is up, and stores who is banned (status, get <jail> banip --with-time) in ban_state. Every address goes
  through filter_var/inet_pton, jails through a name pattern, every word through escapeshellarg. The page (tabs Web
  firewall and PHP / Bans / Flood protection) runs the daemon at once (zsudo daemon-run, at most every 10 s) and reloads
  itself while something waits. mod_reqtimeout is loaded with Apache's defaults on every system (Debian/Ubuntu enable
  it; RHEL's base modules load it; Sentora's module trim keeps it): the page only shows it. The access logs are closed to
  the sites (domains/), so a site can not forge lines to get someone banned. Tests: tests/php_hardening/bans.sh (fake
  fail2ban; with a real one on the machine also fail2ban-regex and -t; the Add-ons workflow installs fail2ban);
  .github/scripts/modsec-test.sh (install test, real panels, five systems): hand ban and unban, a real flood from an
  address on the box banned, the jail gone when switched off, no SELinux refusals, remove takes the jails away.

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
- **101** (owner, 2026-11-23: "a quick top-down look at everything on the panel", as the old one's pages were): tabs Usage,
  Domains, Mail, MySQL, FTP, Cron jobs, Docker apps (only while x_dh_containers exists: zgod::HasDocker), Packages: plain
  tables (controller Table/ListCard/Find/Match; on a phone each row a card with data-label). zgod::All($what) holds the
  queries (never a password: x_mysql_users only name/access/created). Activity by ac_lastlogon_ts: recent 7 days, active
  90, inactive, never (zgod::Activity). Account page: MySQL users with their databases (x_mysql_dbmap), DNS records by
  domain (x_dns), Docker apps; Shadow posts to ./?module=shadowing&action=ShadowUser (inShadow_<id>, inReturn=zgod) when
  Shadowing is on, never for yourself; Edit/Delete link to Manage Clients (owner agreed: zGod never deletes).
  Bug fixed: x_logs.lg_when_ts is a TIMESTAMP (the only non-number _ts column): zGod 100 wrote time() into it and MySQL
  strict mode refused, after the suspend/mailbox change was made. Now left to the database; read with zgod::When. The
  test's stand-in has it as TEXT DEFAULT CURRENT_TIMESTAMP. Found by the Elk whole-way test on a real panel.

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

## Docker Hosting (add-on, sentora-repo/docker_hosting.zpp, 141, node agent 121; built 2026-11-11 at the owner's request)

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
- gVisor and SELinux (2026-11-16: runsc refuses an OCI spec with an SELinux label, and run under container_t it can not set itself up; so until agent 121 those apps ran on the standard runtime). **Agent 121 (2026-11-24, owner asked for it): gVisor with SELinux on AlmaLinux/Rocky/RHEL/Fedora nodes**, in Sentora's own SELinux domain: `docker-node/selinux/sentora_runsc.te/.fc` (made with container-selinux's own `container_domain_template(sentora_runsc, container)`, plus `container_net_domain` = container_t's network; extra: `sentora_runsc_state_t` for its state, mounts in its own namespace (proc mount/unmount, root_t/tmp_t/container_file_t mounton, tmpfs remount), a no-op setattr of /dev/null, conmon's fifo setattr; started only from container_runtime_t). The wrapper `docker-node/runsc-sentora` (embedded in the installer as /usr/local/bin/runsc-sentora; the node test checks the copies are the same): with /sys/fs/selinux/enforce = 1, on create/run it takes process.selinuxLabel out of the bundle's config.json (and mountLabel, context= mount options), makes /run/user/<uid>/sentora-runsc/<container id> (state type by name transition) and `chcon -l` it to the app's level, gives runsc `--root` there (every runsc command), writes the sandbox's pid file there and copies it to Podman's path itself, and starts runsc with `runcon system_u:system_r:sentora_runsc_t:<the app's level>`; delete unmounts null-netns and removes the folder; exec and the rest run as Podman does (container_runtime_t). Without SELinux the wrapper is as before (no change for Debian/Ubuntu nodes). Installer: on MAC=selinux builds the module against the machine's policy (selinux-policy-devel, python3 installed if missing), semodule -i, restorecon runsc/gvisor-bin, `RUNSC_SELINUX=1` in platform.conf; else notes it and the apps stay on the standard runtime. Agent `Runsc()`: under Enforcing only with RUNSC_SELINUX=1. Purge removes the module. Apps made before keep their runtime until recreated (an update or a change of Extra isolation). Proven by Actions > gVisor SELinux probe (real AlmaLinux 10 VM, enforcing): sandbox, gofer and sentry in sentora_runsc_t at the app's level (two customers, two apps of one customer); published port, volume, restart, exec; no refusal from the domain; per-permission comparison with container_t (only the extras above); side by side with container_t a process in the domain is refused another app's files and gVisor state, Podman's files, the customer's home, /etc/shadow, /root, /etc writes, pid 1, mounts, the system bus (the same answers as container_t).
- Docker node test alma10-vm: the same docker-node-test.sh on an AlmaLinux 10 cloud image under KVM (in_box over SSH in /, box_cp, box_restart = reboot; the panel at 10.0.2.2), SELinux enforcing; every AVC/USER_AVC/SELINUX_ERR fails it. Since 2026-11-24 they are read from /var/log/audit/audit.log itself, from the line count at the start (ausearch is not on the image: the check before saw nothing), and the app must run in gVisor there, its sandbox in sentora_runsc_t at the app's level.
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
  - Viewer logins (owner, 2026-11-23: a kiosk; zadmin is not a Kibana login): `sentora-elk-node viewer add|password|remove|list
    NAME` (tool's `viewer` case). Each call PUTs the Kibana role sentora_viewer (/api/security/role: base read in every
    space, ES read + view_index_metadata on the same indices as sentora_admin); users are ES native users with exactly
    that role; password/remove refuse any login that is not one; names [a-z][a-z0-9_-]{2,30}, not sentora*/elastic/kibana/...
    Kibana's default session lifespan (30 days) applies. Whole-way test: log in, open a dashboard, refused change/make/delete,
    list, refused names, new password, removed.
  - Dashboards someone made (owner, 2026-11-23: "upload a panel ... with one command"): `sentora-elk-node dashboard
    install FILE.ndjson|https://.. [NAME]` / `list` / `remove NAME` (elk-node/sources/dashboards.py, python3 -I). Checks the
    Kibana export (types allowed: dashboard, visualization, lens, map, search, index-pattern, tag, canvas-*, links,
    event-annotation-group; never action/alert/cases/...; no id sentora-*; at least one dashboard; 20 MB; https only),
    imports through /api/saved_objects/_import?overwrite=true as elastic, keeps what was added in
    /etc/sentora-elk/dashboards/NAME.json (700/600); remove deletes only those (an object another added file also has stays).
    Tested in the whole-way test (added, listed, connector/takeover/junk/http refused, kept by the update, removed).
  - zGod on the node (owner, 2026-11-23): a node dashboard (sentora-zgod, build.py), not an uploaded file: the node has to
    read zGod's lines anyway (Logstash kind zgod), so the board comes with it. zGod 101's OnDaemonRun hook (zgod::ElkRun)
    writes log_dir/zgod.log (640; > 5 MB -> zgod.log.1): type account (hourly), server (hourly), action (from x_logs rows of
    module zgod after the last id sent, x_zgod elk_log_id; the first run starts from now). Log Forwarder 214 follows it in
    the "panel" group. Logstash: only known keys, numbers as doubles, strings cut (64, reason 200); the line's own
    @timestamp. Template: sentora.zgod.* mapped; the installer lazily rolls logs-sentora-default over when sentora.zgod.disk
    is not mapped yet. Whole-way test: zGod installed on the test panel, its lines read back on the node.
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

- **Released 2027.104 (2026-11-27, ordinary; the last of the owner's ten):** on Windows the SpamAV figures from Stalwart's
  log (a 0-byte read stopped the daemon in testing: fixed, and both new daemon parts catch their own errors), PHP
  Versions > Extensions, the server-wide sender lists as Stalwart spam rules (and ReloadSettings after spam changes), and
  the refusals log Apache may write. The owner tested 2027.103 and found nothing. Owner, 2026-11-27: another ten releases as I see fit
  (2027.105 to 2027.114), each still announced first with 10 seconds to stop it.
- **Released 2027.103 (2026-11-26, ordinary):** Mail > SpamAV for mailbox owners on Windows (one Sieve script per mailbox
  with its forwarder; the score compared by its whole part, since Stalwart's numeric compare does not read "9.10"). The
  Windows test sends real messages: no choice -> Junk Mail, inbox choice, blocked and allowed senders, sensitivity 10 on
  both sides (11.6 to Junk, 9.1 to the inbox), filter off. Stalwart's log has "message-ingest.spam/ham" lines with from, to,
  accountId and mailboxId (the next step: the SpamAV figures on Windows from that log).
- **Released 2027.102 (2026-11-26, ordinary):** each domain's PHP version on Windows, AutoIP zones, the release waits for the
  Windows test.
- **Released 2027.101 (2026-11-26, ordinary, owner: "get everything released"):** on Windows Mail Config, SpamAV Admin
  (ClamAV, Stalwart's spam filter, website file scanning), Branding, AutoIP, Restore, Matomo, PHP 8.4/8.3 per domain and
  the PHP Versions page; the parked-domain timing fix. Linux install test passed on all five; the release's Windows test
  stopped at a stale line of the test (PHP Versions in the kept-out list), and the release published anyway (release.yml
  does not wait for the Windows test). The full Windows test afterwards found that the pages (Apache's account) could not
  see bin\php-X.Y: fixed on master2027 for 2027.102 (the versions by their folders' names).
- 2027.97 to 2027.100 (2026-11-25/26): the Windows panel step by step (Panel Update, Apache Config, Protected Directories,
  FTP Config, Module/Widget Admin, Log Viewer, Server Monitor, Site Health, Forwarders, File Manager, Backup Config).
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


**Native Windows panel, the way it is built (owner, 2026-11-24; started 2026-11-24 with step 1):** the same panel code;
`sys_versions::IsWindows()` tells the two apart (zPanel's `ShowOSPlatformVersion()` still exists; its old Windows branches
call zPanel's long-gone C:\Sentora\bin\zpss tools and get replaced as each part is done). **Every program name, path and
service name comes from the settings (x_settings, hidden ones for root's commands), never from the code, on Windows as on
Linux; anything still written in the code is moved to a setting when its part is done** (the survey found `systemctl` in about
10 places, /var/lib/sentora in 77, `useradd`, `hostname -I`, `zip`, `mysqldump`). A program of the same kind is swapped by
settings alone; a different kind (Postfix -> Stalwart, ProFTPD -> SFTPGo, BIND -> CoreDNS) by a driver the settings name, as
zPanel did (`mailserver_php`, `ftp_php`): a new app = one driver file + its settings. Root's jobs on Windows: a Sentora service
(SYSTEM at first, a narrower account later) running a fixed list of jobs from a locked queue folder, like zsudo; the panel's
Apache no longer runs as LocalSystem (zPanel did, and the stack installer still does). Step 1 (2026-11-24): a module runs on
Windows (page, menu, daemon jobs) only when its module.xml says `<windows>yes</windows>` (`ui_module::ReadyHere`; always true
on Linux); the database-only modules are marked. The state folder /var/lib/sentora is a junction on Windows
(\var\lib\sentora on the panel's drive -> C:\zpanel\data\sentora), so the 77 places that name it work unchanged. Probe:
sentora-windows-2027 Actions > Panel on Windows probe (core branch as input): unchanged panel logged in and 12 of 14 modules
loaded; the daemon stopped at Apache Config's posix_getpwnam(). Per-account PHP on Windows: one php-cgi per account as its own
Windows user (never one PHP for all: less secure) - to be proven before building on it (step 2).
Step 1 tested whole (2026-11-24, sentora-windows-2027 install-test.yml with -WithPanel, Windows Server 2025): the installer
puts the panel in C:\zpanel\panel (its database, 24 Windows settings, zadmin's password in configs\panel), Apache runs as
`NT SERVICE\Apache2` (service SID on; C:\zpanel locked to Administrators and SYSTEM, Apache's account only on its own folders,
the folders above them listed, C:\var, C:\var\lib and the junction itself), root jobs by the service SentoraZsudo
(bin/zsudo.php --run-queue as SYSTEM; the panel's side is bin/zsudo.bat, the `zsudo` setting: .bat because PHP on Windows
counts only .exe as runnable, hence `sys_versions::CanRun()` in the pages that ask zsudo), the daemon by the task
'Sentora daemon' every 5 minutes as SYSTEM. Proven: zadmin logs in, the ready modules load, the others are kept out (page,
menu, dashboard), "Run the daemon now" goes page -> zsudo -> SentoraZsudo -> daemon, zsudo refuses what is not on its list,
Apache's account can not read MySQL's or zadmin's password, change the panel's code or php.ini or write root's state; update
and repair stop SentoraZsudo and the task while PHP's folder is replaced; uninstall removes both.
Step 2 proven (2026-11-24, sentora-windows-2027 Actions > "PHP per account on Windows probe", tests/php-isolation-probe.ps1,
Windows Server 2025; not built into the installer yet): **each hosting account's PHP runs as its own Windows account** -
a service SentoraPHP-<account> under its virtual account NT SERVICE\SentoraPHP-<account> (no password to keep), running
bin\sentora-php-pool.php (php.exe -n), which keeps three php-cgi -b 127.0.0.1:<port> running and restarts any that stops;
Apache (mod_proxy_fcgi + a balancer of the three, ProxyFCGIBackendType GENERIC, SCRIPT_FILENAME set from DOCUMENT_ROOT and
SCRIPT_NAME, the address ending in "/" or Apache on Windows glues the drive letter to the port) passes each site's PHP to its
account's. Rights: the local group SentoraPHP (every account's service in it) reads bin\php, NSSM and the launcher; each
account changes only hostdata\<account> and reads its own php.ini and guard; the folders above (C:\zpanel, configs,
configs\php, bin, hostdata) need "list" for the group (with "read attributes" only, php-cgi silently runs with no php.ini
at all). **The fences go on php-cgi's command line** (-d, each value in quotes: -d is read like a php.ini line, where ; starts
a note): open_basedir = the account's folder + its guard, auto_prepend_file = its guard, so they hold even if php.ini can not
be read. **A key per account:** Apache adds SENTORA_KEY (64 hex characters, only in Apache's settings and the account's
guard) to each request it passes; the guard refuses a request without it (403). Needed because any account's PHP can open
a connection to another account's php-cgi port and ask it to run that account's scripts as that account, and Windows
Firewall does not filter connections inside the server (a block rule by service and by user were both tried: no effect).
Proven: alice's PHP can not read, list or write bob's files, read the MySQL password, bob's php.ini, httpd.conf or the panel,
write PHP's or Windows' folders or zsudo's queue, stop Apache or bob's PHP, kill bob's php-cgi, or act as an administrator;
straight at bob's port, bob's own script is refused by the guard (also with PHP_VALUE / PHP_ADMIN_VALUE trying to switch it
off) and a script alice planted in C:\Windows\Temp is refused by open_basedir ("No input file specified"); three slow pages at
once take 2 s (one php-cgi answers one at a time, hence three); a killed php-cgi is back within a second; about 20-28 MB each.
Still open: whether hostdata needs "list" (it shows the other accounts' folder names, not their contents), a per-account
pool size setting, and building it into the installer and the panel (the account's service made by zsudo, its ports and key
in the settings).
Built into the Windows installer (2026-11-24, the install test's "PHP per hosting account" step passes, also after an update
and a repair): bin\phpaccount.ps1 add NAME [-Processes 3] | remove NAME | list | repair (saved settings and key in
configs\php\accounts\NAME.json, Administrators only; ports 16 per account from 20000; Apache's part in
configs\apache\php-accounts\NAME.conf: a <Directory hostdata/NAME> sending its .php files to NAME's PHP with NAME's key, so any
site whose folder is in hostdata\NAME gets it with no more settings); the guard also hides the key from the site's $_SERVER and
$_ENV (getenv() still shows the account its own key, which opens only its own PHP). hostdata in httpd.conf: a PHP file of no
account is refused (403), php_admin_flag engine Off (a .htaccess can not hand files to the panel's own PHP), no includes or
CGI, AllowOverride AuthConfig FileInfo Indexes Limit Options=Indexes,MultiViews,SymLinksIfOwnerMatch, Options
+SymLinksIfOwnerMatch: a junction an account makes into another's folder is not followed (403). Trade-off to look at: a
site's .htaccess with "Options +FollowSymLinks" (Drupal's, some Laravel ones) now gets a 500; whether FollowSymLinks may be
allowed again without the junction leading in is not tested yet. The installer rebuilds every account on each run (keys
kept) and update/repair stop their services while PHP is replaced; uninstall removes them and the group. Next: the panel
makes and removes the accounts through SentoraZsudo (a "phpaccount add/remove NAME" action) when a client is made or
deleted.
The panel part (2026-11-24, tested in the Windows install test, also after the repair): sys_winphp (Windows only). The daemon
(SYSTEM) writes the live accounts' names to \var\lib\sentora\phpaccounts\wanted.txt (a folder only root's jobs write; Apache's
account can not: the panel test checks it) and, when the list changed or once a day, asks SentoraZsudo for "phpaccount-sync"
(no names in the request); phpaccount.ps1 sync gives each listed account its PHP and removes only the accounts the panel
made ("panel": true in their saved settings; ones added by hand stay), then one gentle Apache restart (httpd -k restart).
Making or deleting a client in Manage Clients only asks for a daemon run (sys_winphp::Soon), so a page can not name an
account to add or remove. Every live account gets one, zadmin too. Names: the panel's user names (a-z, 0-9, -, 1-63).
Installers: install-web-stack.ps1 is install-zpanel-stack.ps1 with $Edition = 'stack' (Apache, PHP and MySQL only, nothing
else asked; a switch still adds a part; the owner wants it kept for other uses); the release and the install test check the
two differ in that line only, and the test installs the copy on its own server. Per-account PHP and the hostdata lockdown
come only with the panel; without it PHP for hostdata stays inside Apache.
Websites (2026-11-25, tested in the Windows install test): sys_winvhosts (Windows only), run by the Domains module's daemon
hook when apache_changed is set or the file is missing. It writes every live domain (and its www.), subdomain and parked
domain into the panel's own file (apache_vhost = configs/apache/sentora-vhosts.conf, included after the administrator's
httpd-vhosts.conf, whose first site stays the one for names no site claims), makes each domain's folder, error pages and
welcome page as SYSTEM (Apache's account may not write in hostdata), tests with httpd -t and asks SentoraZsudo for
"service Apache2 graceful" (httpd -k restart); a refused file is put back. A site's PHP is its account's own with nothing in
the vhost (phpaccount.ps1's <Directory hostdata/NAME>). A disabled account's sites show static/disabled; parked domains
the parking page; logs in logs/domains/<user>/. Domains, Sub Domains and Parked Domains are ready for Windows; zPanel's
dead setroute.exe calls are gone. (ZPanel X 10.1.0's Windows installer, the owner's copy, 2026-11-25, kept out of the repositories: bin\zpss held
configurex.bat (copy the ms_windows config pack, import the SQL, put zppy/setso/setzadmin in %windir%, run
enviroment_configure.php, restart Apache, hMailServer and BIND), MySQL_User_Cleanup.sql (drop anonymous users), and
enviroment_configure.php (databases, hMailServer.ini, passwords, login_details.txt); setroute.exe, not in the copy, only
added a hosts-file entry so the server reached its own panel and new domains. Its Windows settings, for the drivers still to
come: FileZilla Server (ftp_service_root, ftp_config_file "FileZilla Server.xml"), BIND (bind_dir, named_dir, named_check*),
hMailServer (its own database zpanel_hmail), cron_file c:/windows/system32/crontab (a cron service for Windows), zip_exe 7-Zip.
Sentora 2027 uses SFTPGo, Stalwart, CoreDNS and Task Scheduler instead.) Not built yet on Windows: the quota pages, custom vhost entries, port forwarding.
HTTPS per website (2026-11-25): the Sencrypt page works on Windows (self-signed, your own certificate, Let's Encrypt).
Certificates and keys are kept in C:\zpanel\data\sentora\certs\<user>\ssl\sencrypt\ (sys_winvhosts::CertHome; on Linux it
is still hostdata/<user>), a folder only Administrators, SYSTEM and Apache's account may use, so the accounts' own PHP can
not read a key. sys_winvhosts gives a domain whose vh_ssl_tx names files there (readable, key matching the certificate,
fullchain.pem when Let's Encrypt left one) an HTTPS site on ssl.conf's port, with only the file paths written (never the
stored text), and its plain-HTTP site sends visitors to HTTPS (301) except /.well-known/acme-challenge/, which every site
serves from C:\zpanel\data\sentora\acme (plain files, PHP off). A fingerprint line per HTTPS site makes a renewed
certificate a change, so Apache restarts and takes it; Sencrypt's daily renewal asks for that too. The installer writes
configs\apache\ssl.conf (Listen 443, TLS 1.2/1.3 and the ciphers in one place, and with the panel but no mail the panel as
the site for names no website claims, on configs\ssl\server.crt), included before the sites with mail.conf; firewall: 443
for Apache. On Windows PHP has no openssl.cnf of its own, so key and request calls pass PHP's extras\ssl\openssl.cnf
(sys_winvhosts::OpensslArgs). The panel's own certificate is not offered on Windows yet (it uses configs\ssl\server.crt;
the page says so). Tested in the Windows install test with a self-signed certificate (served with its own name, PHP the
account's own over HTTPS, 301 from HTTP, check files served and never run, the account's PHP refused the key); Let's
Encrypt itself is not tested there (no public name). An older install's mail.conf opened 443 itself: the installer takes its Listen and TLS lines out (they are ssl.conf's), or Apache would refuse the port twice on a repair or update. The test also deletes the certificate on the Sencrypt page (files go, the domain is back on plain HTTP). (The panel refuses a parked domain that looks like a subdomain of another account's domain, e.g. one sharing
example.net with a domain already there: an old rule, not Windows'.)
DNS, FTP, mailboxes and cron on Windows (2026-11-25, tested in the Windows install test):
- DNS Manager / DNS Config: the daemon writes each zone as CoreDNS's db.DOMAIN in zone_dir, its first line marking it as the
  panel's (only those are written over or removed; zones made with dnszone.ps1 are left alone); CoreDNS takes a raised serial
  within 15 seconds, so there is no named.conf, checker or reload on Windows. DNS Config's start/stop/reload go to zsudo
  (service CoreDNS). New records point at server_ip, which the Windows installer now sets.
- FTP Accounts (ftp_php = sftpgo.php): the page saves x_ftpaccounts as on Linux and asks for a daemon run; sys_winftp (the
  daemon, SYSTEM) keeps SFTPGo's users in line through its API (ftp_api, administrator from ftp_env, root's jobs only), only
  users whose description says the panel made them; the SHA-512 crypt hash goes over as it is; access types become SFTPGo
  permissions; a switched-off client's logins get status 0 and their sessions are ended; a new home folder is made by the
  daemon (Apache's account may not write in hostdata).
- Mailboxes: the Windows installer makes the mail database (mailserver_db, sentora_postfix, from the Linux SQL), so the page
  and its postfix.php driver work as on Linux; sys_winmail (the daemon) brings Stalwart's domains and accounts in line through
  its JMAP management API (mail_api, administrator from mail_admin), only the accounts it made (its state file); the hash is
  Stalwart's secret as it is (the crypt hash alone, $6$...; Stalwart keeps a $-hash as given; credentials go as a JSON object {"0": ...}, not a list); a switched-off mailbox has Stalwart's authenticate, emailReceive and emailSend permissions switched off (permissions @type Merge), its password kept. Aliases, forwarders and lists are not on Windows yet.
- Cron Manager: the task 'Sentora cron' (SYSTEM, every minute) runs bin/sentora-cron.php, which starts each task due now in
  its own process and hands the script to the account's own PHP over FastCGI with the account's key (as Apache does), so it
  runs as NT SERVICE\SentoraPHP-NAME inside its fences; never two runs at once, cron_min_interval and cron_max_runtime as on
  Linux, the output in tmp/cron-<id>.log. Limits: the site's own max_execution_time applies, and php-cgi reports exit code 0.
- Settings the Windows installer adds when missing (hidden): ftp_php, ftp_api, ftp_env, mail_api, mail_admin, server_ip.
- Mail aliases and distribution lists (2026-11-25): from the mail database's alias table, as on Linux: an address that delivers
  to one of the panel's mailboxes becomes an alias of that Stalwart account (its aliases, an object keyed by position), any
  other address with its recipients a Stalwart mailing list (x:MailingList, recipients as {address: true}; only the lists the
  panel made). Forwarders are not on Windows yet (Stalwart forwards from a live mailbox only by a Sieve script).
- Webmail (Roundcube at /webmail on Windows), Services, Domain Logs (the logs folder from log_dir, not /var/sentora/logs written
  in), phpMyAdmin (shipped in the panel; the installer writes its config.inc.php as preconf/phpmyadmin on Linux: cookie login,
  127.0.0.1, its secret in configs\panel, TempDir in the state folder, no setup folder) and Backup Manager ("Back up now" asks
  zsudo account-backup ID: the worker as SYSTEM, the site zipped with PHP's ZipArchive without following links or junctions,
  mysqldump as on Linux; the backups folder gives Apache's account change rights so the page downloads and deletes; the
  download script uses the panel's class loader, as Windows' DocumentRoot has no slash at the end).
- Sentora Config (2026-11-25): the daemon report's path comes from log_dir (not written in), and on Windows the page names
  SentoraZsudo and leaves out the Linux-only sentora-change-domain hint. "Site not found falls back to panel login" works on
  Windows: sys_winvhosts writes configs/apache/sentora-fallback.conf (included right after ssl.conf, before every site); off,
  it is a first *:80 and *:443 site that answers unknown names 404 (with panel_ip_access on, the server's own addresses and
  localhost still reach the login).
- Protected Directories (2026-11-25): the page saves x_htpasswd_file / x_htpasswd_user as on Linux and asks for a daemon run;
  the daemon (SYSTEM, Apache's account may not write in hostdata) writes the folder's .htaccess block (AuthUserFile quoted)
  and its password file (bcrypt), and takes both away when the protection is deleted.
- Panel Update (2026-11-25): Check now and Update now work on Windows. The page asks zsudo panel-check / panel-update;
  SentoraZsudo starts the task 'Sentora update check' (now every hour, sentora-update.ps1 -Check) or 'Sentora update'
  (sentora-update.ps1 -FromPanel, no schedule; a task of its own because the update stops SentoraZsudo). sentora-update.ps1
  writes the same files as Linux's sentora-update (available, status with start/step/end/done/fail lines, installed) in
  data\sentora\update (only root's jobs write, Apache's account reads), so the page shows its 5 steps. No going back from
  the panel on Windows: the update keeps a copy of the settings, and the installer of the release before, run as a repair,
  goes back. The install test now updates through the page (Check now, a wrong password refused, Update now, steps seen).
- Apache Config (2026-11-25): on Windows the page offers what sys_winvhosts uses: start pages (dir_index), the access log
  format, keeping switched-off clients' sites online, shared domains, the lines for every website (global_vhcustom) and, per
  domain, "Website answers" and its Extra directives (the port, IP, SSL box, open_basedir, FollowSymLinks and PHP settings
  are Linux's; on Windows FollowSymLinks is never allowed). The entries go into sentora-vhosts.conf between
  sys_apachecustom's markers, filtered as on Linux; when httpd -t refuses the file, the entry it names is switched off (the
  page shows it) and the rest goes live. Linux's Apache Config hooks do nothing on Windows (a deleted client's files stay).
- FTP Config and Module Admin (2026-11-25): FTP Config names the FTP program from the ftp_php driver (SFTPGo / ProFTPD),
  the passive ports from ftp_passive_ports (the Windows installer sets 50000-50100; without it ProFTPD's 60000-65535) and
  hides ProFTPD's database setting under SFTPGo. Module Admin on Windows marks the modules not ready there ("not on Windows
  yet") and has no add-on upload (add-ons are installed by Linux's daemon job; none is ready for Windows yet).
- Widget Admin (2026-11-25): works on Windows as on Linux (queue and results in the state folder through the junction; the
  daemon installs into panel\etc\dashboard-widgets as SYSTEM); its PHP syntax check now uses the daemon's own PHP
  (PHP_BINARY), as "php" is not on Windows' path. Tested: a widget uploaded on the page is installed.
- System Log Viewer (2026-11-25): its log folders come from log_dir ({log_dir} in its list; /var/sentora/logs on Linux as
  before), with a Windows list: panel, panel security (state folder), sites, Apache, PHP, SFTPGo, Stalwart/ClamAV/webmail,
  CoreDNS, MySQL (the ones Apache's account may not read are listed as locked). The problems log hook stays Linux's.
- Server Monitor (2026-11-25): on Windows the service SentoraStats (NSSM, SYSTEM) runs bin/sentora-winstats.ps1, which
  writes the page's figures every 3 seconds to the state folder's monitor\winstats.json (CPU time from the raw processor
  counters in 10 ms ticks, memory, page file as swap, network and disk byte totals, local disks, processes with owner, every
  service's state, the system's name); Apache's account reads that folder only. stats.php hands it on and picks the panel's
  services by the names in the settings (apache_sn, db_service, ftp_service, bind_service, mail_service, clamd_service; the
  installer now sets db_service, mail_service, clamd_service, freshclam_service). "Load" is the processor queue length
  (Windows has no load average). The daemon's alerts use the same figures (disks, stopped services); the Hardening card is
  Linux's and hidden on Windows. The panel's PHP gets no access to Windows' counters (no COM): less to misuse.
- Site Health (2026-11-25): on Windows the daemon (SYSTEM) counts as root for its hourly check; its JSON is written as on
  Linux (tempnam's backslash path compared by real path, no chgrp), old account files found with scandir; the installer
  lets Apache's account read data\sentora\sitehealth.
- Note for tests: an upload sent with curl and a copy of the session cookie renews the panel session, so the test logs in
  again after it (four checks once failed only because of that).
- Forwarders (2026-11-25): a probe showed Stalwart's administrator may manage the Sieve scripts of every mailbox's own JMAP
  account (SieveScript/get with the account's x:Account id worked; maxNumberRedirects is 1). sys_winmail reads forwarders
  from the mail database's alias table as on Linux (the mailbox's own address in goto = keep a copy) and puts a script
  "sentora-forward" on the mailbox (Blob/upload, SieveScript/set, onSuccessActivateScript): redirect, or redirect :copy.
  Two or more addresses go through a Stalwart list of the panel's, sentora-fwd-<hash>@the domain. A script the mailbox's
  owner switched on (their own filters) is never replaced: the daemon's log says so. Removed with the forwarder.
- File Manager (2026-11-25): on Windows it runs as the account's own PHP, never the panel's (Apache's account may not write
  in hostdata, on purpose). phpaccount.ps1 gives each account an address /sentora-fm/NAME/ (Alias to
  C:\zpanel\filemanager, a copy of panel/modules/file_manager/app made by phpaccount.ps1 at each run (robocopy /MIR;
  Administrators and SYSTEM write, the SentoraPHP group and Apache read): the panel's folder stays closed to the accounts
  (giving them "list" on it failed the isolation test, so it is not done); a LocationMatch hands only index.php and
  raw.php there to NAME's php-cgi with NAME's key and the script's own SCRIPT_FILENAME, its other PHP and JSON files are
  denied, the copy is in the account's open_basedir. The panel's code/open.php reads the account's key from
  configs/apache/php-accounts/NAME.conf and sends a pass (account, one minute, a one-time number, HMAC-SHA256 with the key);
  app/winaccount.inc.php checks it against getenv('SENTORA_KEY'), the address and the account's folder (open_basedir's first
  entry), refuses a number used before, and starts the file manager's own session (cookie only for /sentora-fm/NAME/,
  SameSite Strict, 2 hours idle). The account's own scripts know the key too: it opens nothing but the account's own files.
  On Windows the panel's own PHP refuses app/index.php. raw.php compares paths with forward slashes on Windows.
- Backup Config (2026-11-26): on Windows the Client backups tab works as on Linux (the daily backup of every account and the
  purge: sys_backup::Create / Purge, OnDaemonDay). The off-server backup has one destination there, a folder or a network
  share (with its own user and password, stored encrypted, connected with net use for the run only):
  bin/sentora-offsite-backup.php (--check, --now, --cron; SYSTEM, started by the daemon at the time set, once a day, and for
  Test connection / Back up now) writes <folder>\<server>\<date>\databases\<db>.sql.gz (every database, MySQL's root
  password from configs\mysql), config\configs.zip, manifest.json, and keeps files\hostdata and files\mail as one
  up-to-date copy (robocopy /MIR /XJ; Stalwart stops for the mail copy); nights beyond "Nights kept" go. Same state file as
  Linux (offsite-backup.json). Never inside C:\zpanel. Restore is Linux's (sentora-panel-restore): the tab is hidden on
  Windows; copying back is by hand (plain SQL dumps).
- The daemon's "one more run" (a page asked while a run was busy: daemon-again) is now a process of its own, started when
  the run is done (2026-11-26): it was a second pass in the same process, which stopped with "Cannot redeclare function"
  at the first hook that declares functions (12 do), on Linux as on Windows. The off-server job's output goes to its log
  (a background job writing to the daemon's closed output stopped at its first line).
- Websites on Windows (2026-11-26): sys_winvhosts::Run clears apache_changed before it reads the domains (it cleared it
  at the end, so a parked domain saved during a run stayed out until the next change: the install test's flaky parked
  domain).
- Mail Config (2026-11-26): on Windows its own settings (added to x_settings by sys_winmail::EnsureSettings, defaults
  = what Roundcube and Stalwart do by themselves): the webmail's language, time zone, new-mail check, largest message and
  log-out (the daemon writes configs\stalwart\roundcube-panel.inc.php in place, setting webmail_panel_conf; Roundcube's
  settings include it), ActiveSync on or off (configs\apache\mail-panel.conf, setting mail_apache_conf, included in
  mail.conf's site: off = Require all denied on /Microsoft-Server-ActiveSync; tested with httpd -t, put back when refused),
  and Stalwart's largest message (x:MtaStageData and x:Email maxMessageSize) and wrong passwords before a block
  (x:Security authBanRate, per address per day), set through its API only when the panel's value changed
  (phpaccounts\mail-config.json), so a change in Stalwart's own admin pages stays. A mailbox's size (its quota in MB, as
  Dovecot reads it on Linux) is its Stalwart disk quota (x:Account quotas.maxDiskQuota). Names found in Stalwart 1.1.1's
  schema (resources/schema). Linux's SOGo settings are not shown on Windows.
- SpamAV Admin (2026-11-26): on Windows sys_winspamav. The same x_settings, cut to what Windows has (Definitions() keeps
  the Windows ones, with the installer's values as defaults: largest file 64 MB, most data 200 MB, 8 threads, "try later"
  when ClamAV does not answer, never refuse spam). The daemon (SYSTEM) sets only the named lines of clamd.conf and
  freshclam.conf (clamd_conf, freshclam_conf; line ends do not count as a change) and restarts ClamAV or FreshClam when
  one changed; starts or stops both with "Scan mail for viruses"; writes apps\mailscan\options.json (mailscan_options:
  enabled, on_fail), which the virus check page scan.php reads; sets Stalwart's x:SpamSettings (enable, scoreSpam,
  scoreReject) when the panel's values changed (phpaccounts\spamav-stalwart.json); handles Restart and Update signatures
  (freshclam_exe); writes spamav-status.json (services by sc query, signatures from the .cvd/.cld headers in
  clamav_db_dir, clamd_exe --version). A service the installer did not make (ClamAV's list not downloaded) is left out.
  Website file scanning (2026-11-26) is sys_filescan on Windows too: bin/sentora-file-scan run by the daemon (SYSTEM,
  started with start /B, output in logs\panel\filescan-last.log), clamd over 127.0.0.1:3310 (files up to clamd.conf's
  StreamMaxLength), results in data\sentora\filescan.json, quarantine filescan_quarantine (data\quarantine, locked by
  the installer; a moved file takes the folder's rights with icacls /reset, and its folder's again when put back), and a
  folder whose real path leads out of the account (a junction) is never followed. The server-wide senders lists (sa_allow_from,
  sa_block_from; 2026-11-27) are Stalwart spam rules of type Any, sentora_allow and sentora_block
  (SenderRules: matches('(?i)^(pattern|...)$', from) || the same on env_from; * -> .*, ? -> ., . and + as [.] [+], no
  backslashes), tagging SENTORA_ALLOW / SENTORA_BLOCK, whose x:SpamTag scores are -100 / +100; an empty list's rule is
  removed. After rules or scores change the daemon asks x:Action ReloadSettings. Not on Windows: the subject tag and
  "where spam goes" (Stalwart files spam into Junk Mail).
- Mail > SpamAV for mailbox owners (2026-11-26): on Windows too. Stalwart marks mail with X-Spam-Status (Yes/No) and
  X-Spam-Score ("spam, score=11.60") and files spam into "Junk Mail" by itself (seen with GTUBE in the Windows test).
  A mailbox's saved choices (x_spamav_prefs; sys_winmail::SpamRules) become Sieve rules in the panel's one script on the
  mailbox (sentora-forward, shared with its forwarder; PanelScript): allowed senders -> INBOX, blocked -> Junk Mail,
  delete at 15+, the sensitivity compares the score's whole part (variables, relational, i;ascii-numeric; a negative
  score never counts), spam -> Junk Mail or INBOX, else INBOX; filter off -> INBOX. Choices that are Stalwart's own
  handling need no script. As with forwarders, a script the owner made and switched on (webmail filters) is never
  replaced (the daemon log says so). Saving asks for a daemon run (sys_winphp::Soon). "Let users change their spam
  settings" (sa_user_settings) is on the Windows SpamAV Admin page.
- SpamAV figures on Windows (2026-11-26): sys_winspamav::ReadMail (every daemon run, in Apply) reads the new lines of
  Stalwart's log (mail_log_dir, stalwart.log.DATE, level info) and of the virus check's refusals (mail_refused_log; scan.php
  writes "DATE refused: VIRUS from SENDER to A,B") into x_spamav_events / x_spamav_daily with sys_spamav::Store, as Linux
  does from Postfix. A "message-ingest.ham/spam" line counts as checked for each recipient; spam when Stalwart says so or
  when it was filed into Junk Mail (mailboxId [2], how Stalwart numbers a new account's folders). Spam a mailbox keeps in
  the inbox by its own choice is logged as ham, so it is not counted. Where the reading got to: phpaccounts\spamav-mailread.json
  (the first run starts at the end: nothing old is counted). The overview's figures are shown on Windows too.
- Branding (2026-11-26): on Windows too. The look is the database's (as on Linux; GD is in the Windows PHP). Panel
  addresses: sys_winvhosts::Branding writes each one's sites between "# BRANDING: host" lines (plain HTTP: Let's
  Encrypt's check through the folder every site serves, else HTTPS or "being set up"; HTTPS: the panel's folder, run by
  the panel's own PHP); certificates in data\sentora\certs\branding (ctrl_branding::CertDir), got by Branding's daemon
  part with Lescript and sys_winvhosts::AcmeDir as the check folder; saving an address asks for a daemon run at once.
  The daemon part reads the sites file with its \r\n line ends.
- Restore on Windows (2026-11-26): Backup Config's Restore tab, run by bin/sentora-offsite-restore.php (SYSTEM, started by
  backup_admin's daemon hook from the same request file as Linux; log logs\panel\panel-restore.log). --list reads every
  server's nights in the off-server folder (manifest.json) into restore-catalog.json (format 2, 'windows' => true; an
  account's databases = the night's dumps named <user>_*). --account restores one account: its databases from the night
  (the ones it has in the panel now; DROP DATABASE first, then the dump) and its websites from the folder's one up-to-date
  copy (robocopy /E, or /MIR with "remove files the backup does not have"; /XJ, the files take the account folder's
  rights). What it had is saved first in data\before-restore\<user>-<time> (Administrators and SYSTEM only). Not on
  Windows: mailboxes per account (one copy of Stalwart's whole store) and the whole-panel restore (the page says how by hand).
- AutoIP (2026-11-26): on Windows as on Linux (the public address from the IP service, server_ip and the x_dns records
  with the old address, the zones written again), but switched off once at its first Windows run (setting
  autoip_windows_off): a server behind a router would otherwise have its records changed to the router's address
  unasked. No script on Windows (no file there is only root's to change): the page hides it, the daemon refuses it.
- Matomo on Windows (2026-11-26): the installer drives Matomo's own setup pages over http://127.0.0.1 (Host: the panel's
  name; database sentora_matomo, user matomo, super user sentora_admin with its password in configs\panel), gives Apache
  write on etc\apps\matomo\tmp and config, runs the console steps (SentoraSync on, framed pages, no browser archiving,
  no calls home), and unpacks Python 3.13.9 embeddable (pinned SHA-256) into bin\python (setting python_exe).
  matomo_helper::AppDir/Url/Console have Windows branches (sentora_root; plain http on apache_port; the console as
  SYSTEM with the daemon's PHP); the hourly import writes its Python script to a file (cmd.exe takes no lines in -c).
  The panel's own log on Windows is logs\apache\panel-access.log (ctrl_statsdomains::PanelLog).
- PHP versions on Windows (2026-11-26): the installer adds PHP 8.4.28 and 8.3.37 (pinned, -PhpVersions '8.4,8.3') in
  bin\php-X.Y with the main php.ini (its own extension_dir, only the extensions it ships) and sets php_versions_enabled.
  sys_winphp::Sync writes wanted.txt lines "NAME 8.4,8.3" (the enabled versions an account's domains use); phpaccount.ps1
  keeps them in the account's json (each version its own port block, "extra"), writes NAME-84.ini and NAME.pool.json, and
  the account's one service (same Windows account) runs every version's php-cgi (sentora-php-pool.php reads pool.json);
  Apache's part has a pool per version (balancer://php84-NAME). sys_winvhosts names that pool in the domain's own
  Directory once NAME.conf has it (the sync asks for the sites again). sys_phpversions has Windows branches (Installed
  from bin\php-*, RecordLive/LiveState from the pool names). The PHP Versions page itself (extensions, updates) is Linux's.
- The PHP Versions page on Windows (2026-11-26): the versions and their switches (php_versions_enabled); its daemon hook
  returns at once there; older logins, PHP updates and extensions are hidden (Linux's).
- Apache Status on Windows (2026-11-27): the installer loads mod_status only when Apache has mod_lua, and writes
  configs\apache\sentora-guard.lua (the hostdata path written in): for a site whose DocumentRoot is in hostdata it
  refuses server-status/server-info/proxy-server and [P] (filename proxy:), and allows a proxy handler only to
  balancer://php-NAME/ or phpNN-NAME of that account; an output filter (SetOutputFilter in Location /) blanks a status
  page made by [H=]. /server-status needs Require local and the header X-Sentora-Status with the key in
  configs\apache\status-key.txt (hidden setting apache_status_key; only Apache's account and the administrators read
  that folder), so the sites' own PHP on this server can not read it. The module's fetch (code/statusfetch.php) sends
  the key and uses apache_port. On Linux the key is not set yet: there Require local alone (the Lua guard stops
  handler tricks, not a site's PHP reading 127.0.0.1/server-status): to do the same there.
- PHP Versions > Extensions on Windows (2026-11-26): sys_phpext::WinDaemonRun (php_versions daemon hook). Each version's
  php.ini (bin\php for the default, bin\php-X.Y) gets a block between "; --- Sentora: PHP Versions > Extensions" markers
  (WinIni: on = extension=NAME there unless the installer's line is on already; off = the installer's line commented out
  too; settings by sys_phpext::ParseSettings). Each change is tried on php.ini.sentora-try with php.exe -m (it must load or
  unload, and no new warning) before it is kept; the first change keeps php.ini.before-sentora. Then the stamp of
  sys_winphp's sync goes, and phpaccount.ps1 sync rebuilds every account whose php.ini is older than its version's
  (Stale), restarting Apache (the panel's PHP too). The installer keeps the block when it writes php.ini again
  (Get-ExtBlock). Offered: the ext\php_*.dll the build ships; com_dotnet never (COM runs programs). Windows test: bz2 on
  for 8.5, the site's PHP has it, the panel's php -m too, then off.
- Still Linux-only, each needing its own Windows way (2026-11-25): PHP updates per version (a new installer brings new
  builds), GoAccess (no native Windows build), Repo Browser (add-ons are Linux's).
Where Windows lives (owner, 2026-11-25): the native installer moved from RusTus/sentora-windows-2027 (left with a note) to
sentora-installers-2027/windows/server (install-zpanel-stack.ps1; install-web-stack.ps1 = the same file with
$Edition = 'stack', kept for other uses; README.txt; tests/), tested by .github/workflows/windows-server-test.yml there (a
push to windows/server, and every release). Each installers release builds sentora-windows-server.zip with the core's
VERSION written in; the installer takes the panel from the core's and the installers' current releases, and
sentora-update.ps1 the zip from the installers' current release, each file checked against the SHA-256 GitHub keeps for it.

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

**PostgreSQL for clients** (owner's idea, 2026-11-13, back burner; asked again 2026-11-24 and kept there: build it when a client asks, Docker Hosting covers one-off needs meanwhile): an add-on like MySQL Databases (client databases and users under their prefix, counted in the package's database limit; the panel itself stays on MySQL). Needs: PostgreSQL from each system's packages via x_settings, localhost only; one role per client, no superuser; pgsql in the PHP bundles (not built today) and the system PHP's package; PHP-Adminer for web access (it speaks PostgreSQL); client backups, the off-server copy and restore to include pg_dump; size watched by the daemon (PostgreSQL has no quota). Several MySQL versions side by side: not advised.

**A module's menu name per kind of user** (owner's idea, 2026-11-13, "in the future"): a module could show another name in the menu depending on who looks (Payment Plans: "Billing" for a billed client, "Payment Plans" for sellers), much like code/menu.php decides whether it shows at all (ui_moduleloader::Shown). For now Payment Plans keeps its one name; only its page heading differs.

**zGod-style overview** (owner's idea, 2026-11-12): DONE, built as the zGod add-on (101, its own HANDOFF section; Kibana dashboard through Log Forwarder). What follows is the original idea: his old zPanel module zGod let the administrator see every account under zadmin as if it were zadmin's own client: totals and "who uses the most" for disk, traffic, mailboxes and their sizes, quotas (figures only, nothing private). Would be an admin-only page or add-on built on the usage figures the panel already keeps; he may hand over the old module to show what it did (complete rewrite). Matomo's clean-up of old raw visits (delete_logs) is also on hold until he decides. Hosting for the add-on repository and the feedback server was deferred by the owner.
