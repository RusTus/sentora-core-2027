<?php

/**
 * Repo Browser: status and information fragments loaded by the module page.
 * Only a logged-in administrator may use it, and it only contacts
 * repositories that are listed in etc/zppy-cache/repo.list.
 *
 * GET parameters (one per request): request, modinfo, update, repostat or reviews, each printing
 * an HTML fragment; or scan, printing JSON.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
error_reporting(E_ERROR | E_PARSE);
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'modules/repo_browser/code/repohelper.php';

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
if (!$user || (int) $user['usergroupid'] !== 1) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}
session_write_close();
// no panel controller here: the account's own language for ui_language::translate()
ui_language::ForAccount($uid);

if (isset($_GET['request'])) {
    AjaxReturnStatus((string) $_GET['request']);
} elseif (isset($_GET['modinfo'])) {
    AjaxReturnModInfo((string) $_GET['modinfo']);
} elseif (isset($_GET['update'])) {
    AjaxReturnUpdate((string) $_GET['update']);
} elseif (isset($_GET['repostat'])) {
    AjaxReturnDownloads((string) $_GET['repostat']);
} elseif (isset($_GET['scan'])) {
    AjaxReturnScan((string) $_GET['scan']);
} elseif (isset($_GET['reviews'])) {
    AjaxReturnReviews((string) $_GET['reviews']);
}

/**
 * Print what other panels' administrators say about a Sentora-2027 add-on (the feedback server's
 * newest comments), as plain text: nothing in a comment is shown as HTML.
 *
 * @param string $module
 * @return void
 */
function AjaxReturnReviews($module)
{
    $h = 'repo_browser_helper::h';
    $list = class_exists('ctrl_feedback') ? ctrl_feedback::Reviews($module) : null;
    if ($list === null) {
        echo '<p class="rb-sub">' . $h(ui_language::translate('The feedback server can not be asked right now.')) . '</p>';
        return;
    }
    if (!$list) {
        echo '<p class="rb-sub">' . $h(ui_language::translate('No comments yet: only stars.')) . '</p>';
        return;
    }
    $out = '<ul class="rb-review-list">';
    foreach ($list as $r) {
        $out .= '<li><span class="rb-stars-shown">' . str_repeat('&#9733;', $r['stars']) . str_repeat('&#9734;', 5 - $r['stars']) . '</span> '
            . '<span class="rb-sub">' . $h($r['at'] ? date('j M Y', $r['at']) : '') . ($r['version'] !== '' ? ' · ' . $h(str_replace('%s', $r['version'], ui_language::translate('version %s'))) : '') . '</span>'
            . '<p>' . $h($r['comment']) . '</p></li>';
    }
    echo $out . '</ul>';
}

/**
 * Print, as JSON, what a repository offers right now compared with the panel's package list
 * (etc/zppy-cache/package.list, which only the daemon's "zppy update" refreshes): whether it
 * answers, how many modules it lists, the modules that are new in it (with their description
 * from name.xml, at most 12 asked) and those it no longer lists. Only repositories in repo.list
 * are asked.
 *
 * @param string $repo The repository as in repo.list.
 * @return void
 */
function AjaxReturnScan($repo)
{
    header('Content-Type: application/json');
    if (!repo_browser_helper::KnownRepo($repo)) {
        echo json_encode(array('ok' => false));
        return;
    }
    $base = repo_browser_helper::Base($repo);
    $txt = repo_browser_helper::Fetch($base . '/packages.txt');
    if (trim($txt) === '') {
        echo json_encode(array('ok' => false));
        return;
    }
    $offered = array();
    foreach (preg_split('/\r\n|\r|\n/', $txt) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#' && repo_browser_helper::ValidModule($line) && !repo_browser_helper::IsCoreModule($line)) {
            $offered[$line] = true;
        }
    }
    $known = array();
    $list = ctrl_options::GetSystemOption('sentora_root') . 'etc/zppy-cache/package.list';
    foreach (preg_split('/\r\n|\r|\n/', (string) @file_get_contents($list)) as $line) {
        $cols = preg_split('/\s+/', trim($line));
        if (isset($cols[1]) && $cols[1] === $repo && !repo_browser_helper::IsCoreModule($cols[0])) {
            $known[$cols[0]] = true;
        }
    }
    $new = array();
    $asked = 0;
    foreach (array_keys(array_diff_key($offered, $known)) as $module) {
        $row = array('name' => $module, 'desc' => '', 'version' => '', 'installed' => InstalledModule($module) ? true : false);
        if ($asked++ < 12) {
            $info = ModuleXMLTags(null, repo_browser_helper::Fetch($base . '/' . $module . '.xml'));
            if ($info) {
                $row['desc'] = isset($info['desc']) ? (string) $info['desc'] : '';
                $row['version'] = isset($info['latestversion']) && repo_browser_helper::ValidVersion((string) $info['latestversion']) ? (string) $info['latestversion'] : '';
            }
        }
        $new[] = $row;
    }
    echo json_encode(array('ok' => true, 'count' => count($offered), 'new' => $new, 'gone' => array_keys(array_diff_key($known, $offered))));
}

/**
 * An OK / failed label with a tooltip.
 *
 * @param bool $ok True for a green OK label.
 * @param string $title The tooltip text (translated here).
 * @param string $bad Optional. The text of the red label (translated here). Default 'NO'.
 * @return string The HTML.
 */
function Label($ok, $title, $bad = 'NO')
{
    $class = $ok ? 'label-success' : 'label-danger';
    return '<a href="javascript:void(0)" title="' . repo_browser_helper::h(ui_language::translate($title)) . '"><span class="label ' . $class . '">' . repo_browser_helper::h(ui_language::translate($ok ? 'OK' : $bad)) . '</span></a>';
}

/**
 * Print the status label of a repository ("R:::repo") or a package ("M:::<repository address>/module.zpp").
 *
 * Only repositories in repo.list are contacted; prints nothing for anything else.
 *
 * @param string $request The request value.
 * @return void
 */
function AjaxReturnStatus($request)
{
    $parts = explode(':::', $request, 2);
    if (count($parts) !== 2) {
        return;
    }
    if ($parts[0] === 'R') {
        if (!repo_browser_helper::KnownRepo($parts[1])) {
            return;
        }
        $ok = repo_browser_helper::UrlExists(repo_browser_helper::Base($parts[1]) . '/packages.txt');
        echo Label($ok, $ok ? 'Repository appears to be valid.' : 'Repository seems to be invalid or offline. Retry fetches its package list again.', 'FAILED');
    } elseif ($parts[0] === 'M') {
        if (!preg_match('#^(https?)://(.+)/([A-Za-z0-9_-]{1,64})\.zpp$#i', $parts[1], $m)) {
            return;
        }
        $repo = (strtolower($m[1]) === 'https' ? 'https://' : '') . $m[2];
        if (!repo_browser_helper::KnownRepo($repo) && !repo_browser_helper::KnownRepo($repo . '/')) {
            return;
        }
        // only a missing package is shown: a pill on every row of a long list would say nothing
        if (!repo_browser_helper::UrlExists($parts[1])) {
            echo Label(false, 'The package is not in the repository, so it can not be installed or updated from here right now.', 'Package missing');
        }
    }
}

/**
 * The x_modules row of an installed module.
 *
 * @global db_driver $zdbh The panel's database connection.
 * @param string $module The module folder name.
 * @return array<int|string, mixed>|false The row, or false when the module is not installed.
 */
function InstalledModule($module)
{
    global $zdbh;
    $sql = $zdbh->prepare("SELECT * FROM x_modules WHERE mo_folder_vc = :folder");
    $sql->bindParam(':folder', $module);
    $sql->execute();
    return $sql->fetch();
}

/**
 * Print the information box of a module ("repo:::module"): the installed version, or what the repository says about it.
 *
 * @param string $full The "repo:::module" value.
 * @return void
 */
function AjaxReturnModInfo($full)
{
    $split = repo_browser_helper::SplitFull($full);
    if (!$split || !repo_browser_helper::KnownRepo($split[0])) {
        return;
    }
    list($repo, $module) = $split;
    $h = 'repo_browser_helper::h';
    $modinfo = InstalledModule($module);
    if ($modinfo) {
        $info = (array) ModuleXMLTags('modules/' . $module . '/module.xml');
        echo '<p class="rb-desc">' . $h($modinfo['mo_desc_tx']) . '</p><p class="rb-meta">' . str_replace('%s', $h($modinfo['mo_version_in']), $h(ui_language::translate('Version %s'))) . ' · ' . str_replace('%s', MailLink($info), $h(ui_language::translate('By %s')))
            . (SiteLink($info) !== '' ? ' · ' . SiteLink($info) : '') . '</p>';
        return;
    }
    $info = ModuleXMLTags(null, repo_browser_helper::Fetch(repo_browser_helper::Base($repo) . '/' . $module . '.xml'));
    if (!$info) {
        echo '<p class="rb-desc rb-sub">' . $h(ui_language::translate('No description in the repository.')) . '</p>';
        return;
    }
    $ostype = '';
    switch (strtoupper(isset($info['ostype']) ? $info['ostype'] : '')) {
        case 'WIN':
            $ostype = '<p class="rb-os bad">' . $h(ui_language::translate('For Windows servers only: it will not work here.')) . '</p>';
            break;
        case 'LIN':
            $ostype = '';
            break;
        case 'ALL':
            $ostype = '';
            break;
    }
    $meta = array();
    if (isset($info['latestversion'])) {
        $meta[] = str_replace('%s', $h($info['latestversion']), $h(ui_language::translate('Version %s')));
    }
    if (isset($info['authorname'])) {
        $meta[] = str_replace('%s', MailLink($info), $h(ui_language::translate('By %s')));
    }
    if (SiteLink($info) !== '') {
        $meta[] = SiteLink($info);
    }
    echo (isset($info['desc']) ? '<p class="rb-desc">' . $h($info['desc']) . '</p>' : '<p class="rb-desc rb-sub">' . $h(ui_language::translate('No description in the repository.')) . '</p>')
        . ($meta ? '<p class="rb-meta">' . implode(' · ', $meta) . '</p>' : '') . $ostype;
}

/**
 * Print the "new version available" box (and a version-mismatch fix) for an installed module ("repo:::module").
 *
 * Prints nothing when the module is not installed, is a core module or is up to date.
 *
 * @param string $full The "repo:::module" value.
 * @return void
 */
function AjaxReturnUpdate($full)
{
    $split = repo_browser_helper::SplitFull($full);
    if (!$split || !repo_browser_helper::KnownRepo($split[0])) {
        return;
    }
    list($repo, $module) = $split;
    $h = 'repo_browser_helper::h';
    $modinfo = InstalledModule($module);
    if (!$modinfo || repo_browser_helper::IsCoreModule($module)) {
        return;
    }
    $info = ModuleXMLTags(null, repo_browser_helper::Fetch(repo_browser_helper::Base($repo) . '/' . $module . '.xml'));
    if (!$info || !isset($info['latestversion']) || !repo_browser_helper::UrlExists(repo_browser_helper::Base($repo) . '/' . $module . '.zpp')) {
        return;
    }
    $latest = (string) $info['latestversion'];
    $installed = (string) $modinfo['mo_version_in'];
    if (!repo_browser_helper::ValidVersion($latest) || version_compare($latest, $installed, '<=')) {
        return;
    }
    $line = '';
    $local = (array) ModuleXMLTags('modules/' . $module . '/module.xml');
    if (isset($local['version']) && (string) $local['version'] === $latest) {
        $line .= '<div class="rb-notice"><b>' . $h(ui_language::translate('Version number out of step')) . '</b>'
            . '<p>' . strtr($h(ui_language::translate('Sentora thinks that %1$s is version %2$s, but the installed module file says it is version %3$s.')), array('%1$s' => $h($module), '%2$s' => '<b>' . $h($installed) . '</b>', '%3$s' => '<b>' . $h($local['version']) . '</b>')) . '</p>'
            . '<p>' . $h(ui_language::translate('This usually means the module developer did not update the version number in the database when the module was updated. If you just upgraded this module, Repo Browser can correct the number.')) . '</p>'
            . '<p><button class="btn btn-default btn-sm" type="submit" name="inUpdateVersionDB" value="' . $h($local['version'] . ':::' . $module . ':::' . $installed) . '">' . $h(ui_language::translate('Fix the version number')) . '</button></p></div>';
    }
    // (already queued: say so instead of offering the button again)
    $queued = repo_browser_helper::Pending($module) === 'upgrade';
    $line .= '<div class="rb-notice"><b>' . str_replace('%s', $h($latest), $h(ui_language::translate('Version %s is available'))) . '</b>'
        . (isset($info['updatedesc']) ? '<p>' . $h($info['updatedesc']) . '</p>' : '')
        . ($queued ? '<p><b>' . $h(ui_language::translate('Upgrade queued: the daemon runs it within 5 minutes.')) . '</b></p>'
            : '<button class="btn btn-primary btn-sm" type="submit" name="inUpdateModule" value="' . $h($repo . ':::' . $module) . '">' . $h(ui_language::translate('Update')) . '</button>')
        . '</div>';
    echo $line;
}

/**
 * Print the download count of a module ("repo:::module") from the repository's statistics.
 *
 * @param string $full The "repo:::module" value.
 * @return void
 */
function AjaxReturnDownloads($full)
{
    $split = repo_browser_helper::SplitFull($full);
    if (!$split || !repo_browser_helper::KnownRepo($split[0])) {
        return;
    }
    list($repo, $module) = $split;
    // the Sentora-2027 add-ons (on GitHub, which counts nothing): the feedback server's counts, when
    // there is one (asked once for every row, kept 5 minutes); none, no number is shown
    if (class_exists('ctrl_feedback') && rtrim($repo, '/') === ctrl_feedback::ADDON_REPO) {
        $cache = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/etc/tmp/repo-downloads.json';
        $c = is_file($cache) && !is_link($cache) && filemtime($cache) > time() - 300 ? json_decode((string) file_get_contents($cache), true) : null;
        if (!is_array($c) || ($c['base'] ?? '') !== ctrl_feedback::Base() || !isset($c['stats']['counts'])) {
            $c = array('base' => ctrl_feedback::Base(), 'stats' => ctrl_feedback::Downloads());
            @file_put_contents($cache . '.' . getmypid(), json_encode($c));
            @rename($cache . '.' . getmypid(), $cache);
        }
        $parts = array();
        if (isset($c['stats']['counts'][$module])) {
            $n = (int) $c['stats']['counts'][$module];
            $parts[] = $n === 1 ? repo_browser_helper::h(ui_language::translate('1 download')) : str_replace('%s', number_format($n), repo_browser_helper::h(ui_language::translate('%s downloads')));
        }
        if (isset($c['stats']['stars'][$module])) {
            list($avg, $count) = $c['stats']['stars'][$module];
            $parts[] = '<span class="rb-avg" title="' . repo_browser_helper::h(str_replace(array('%1', '%2'), array((string) $avg, (string) (int) $count), ui_language::translate('%1 of 5 stars, from %2 panels'))) . '">&#9733; '
                . repo_browser_helper::h((string) $avg) . ' (' . (int) $count . ')</span> <a href="javascript:void(0)" class="rb-reviews" data-module="' . repo_browser_helper::h($module) . '">'
                . repo_browser_helper::h(ui_language::translate('What others say')) . '</a>';
        }
        echo implode(' · ', $parts);
        return;
    }
    $list = repo_browser_helper::Fetch(repo_browser_helper::Base($repo) . '/repostatslist');
    if ($list !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $list) as $row) {
            $cols = preg_split('/\s+/', trim($row));
            if ($cols[0] === $module && isset($cols[1]) && ctype_digit($cols[1])) {
                echo (int) $cols[1] === 1 ? repo_browser_helper::h(ui_language::translate('1 download')) : str_replace('%s', number_format((int) $cols[1]), repo_browser_helper::h(ui_language::translate('%s downloads')));
                return;
            }
        }
        return;
    }
    $json = json_decode(repo_browser_helper::Fetch(repo_browser_helper::Base($repo) . '/repostats.php?json=true'), true);
    if (is_array($json)) {
        foreach ($json as $stat) {
            if (is_array($stat) && isset($stat['package'], $stat['downloads']) && $stat['package'] === $module) {
                echo (int) $stat['downloads'] === 1 ? repo_browser_helper::h(ui_language::translate('1 download')) : str_replace('%s', number_format((int) $stat['downloads']), repo_browser_helper::h(ui_language::translate('%s downloads')));
                return;
            }
        }
    }
}

/**
 * The author's name, as a mailto link when the e-mail address is valid.
 *
 * @param array<string,string> $info Module tags (see ModuleXMLTags()).
 * @return string The HTML.
 */
function MailLink($info)
{
    $h = 'repo_browser_helper::h';
    $name = isset($info['authorname']) ? $info['authorname'] : '';
    $email = isset($info['authoremail']) ? $info['authoremail'] : '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '<a href="mailto:' . $h($email) . '">' . $h($name) . '</a>';
    }
    return $h($name);
}

/**
 * A "Visit Author Site" link when the author URL is a valid http(s) URL.
 *
 * @param array<string,string> $info Module tags (see ModuleXMLTags()).
 * @return string The HTML, or ''.
 */
function SiteLink($info)
{
    $url = isset($info['authorurl']) ? (string) $info['authorurl'] : '';
    if (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
        return '<a href="' . repo_browser_helper::h($url) . '" target="_blank" rel="noopener noreferrer">' . repo_browser_helper::h(ui_language::translate('Visit Author Site')) . '</a>';
    }
    return '';
}

/**
 * Tags of a module.xml (local file) or update xml (downloaded text).
 *
 * @param string|null $file Optional. Path of a local module.xml (read instead of $xml). Default null.
 * @param string|null $xml Optional. The XML text. Default null.
 * @return array<string, string>|false Tag => value for name, version, desc, updatedesc, authorname, authoremail, authorurl, latestversion, downloadurl and ostype; false when there is none or the XML is not valid.
 */
function ModuleXMLTags($file = null, $xml = null)
{
    if ($file !== null) {
        if (!is_file($file)) {
            return false;
        }
        $xml = fs_filehandler::ReadFileContents($file);
    }
    if (!is_string($xml) || trim($xml) === '') {
        return false;
    }
    try {
        $doc = new xml_reader($xml);
        $doc->Parse();
    } catch (Throwable $e) {
        return false;
    }
    $info = array();
    foreach (array('name', 'version', 'desc', 'updatedesc', 'authorname', 'authoremail', 'authorurl', 'latestversion', 'downloadurl', 'ostype') as $tag) {
        if (isset($doc->document->{$tag}[0]->tagData)) {
            $info[$tag] = $doc->document->{$tag}[0]->tagData;
        }
    }
    return count($info) ? $info : false;
}

?>
