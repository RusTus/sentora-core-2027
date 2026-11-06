<?php

/**
 * Repo Browser: status and information fragments loaded by the module page.
 * Only a logged-in administrator may use it, and it only contacts
 * repositories that are listed in etc/zppy-cache/repo.list.
 */
error_reporting(E_ERROR | E_PARSE);
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'modules/repo_browser/code/repohelper.php';

session_start();
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
if (!$user || (int) $user['usergroupid'] !== 1) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}
session_write_close();

if (isset($_GET['request'])) {
    AjaxReturnStatus((string) $_GET['request']);
} elseif (isset($_GET['modinfo'])) {
    AjaxReturnModInfo((string) $_GET['modinfo']);
} elseif (isset($_GET['update'])) {
    AjaxReturnUpdate((string) $_GET['update']);
} elseif (isset($_GET['repostat'])) {
    AjaxReturnDownloads((string) $_GET['repostat']);
}

function Label($ok, $title, $bad = 'NO')
{
    $class = $ok ? 'label-success' : 'label-danger';
    return '<a href="javascript:void(0)" title="' . repo_browser_helper::h($title) . '"><span class="label ' . $class . '">' . ($ok ? 'OK' : $bad) . '</span></a>';
}

/* "R:::repo" for a repository, "M:::<repository address>/module.zpp" for a package */
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
        $ok = repo_browser_helper::UrlExists($parts[1]);
        echo Label($ok, $ok ? 'Module appears to exist in the repository and is available for installation.'
            : 'Can not find module in the repository! Module is currently not available for installation.');
    }
}

function InstalledModule($module)
{
    global $zdbh;
    $sql = $zdbh->prepare("SELECT * FROM x_modules WHERE mo_folder_vc = :folder");
    $sql->bindParam(':folder', $module);
    $sql->execute();
    return $sql->fetch();
}

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
        echo '<span class="label label-success">Module Installed.</span><p>' . $h($modinfo['mo_desc_tx']) . '</p>Version '
            . $h($modinfo['mo_version_in']) . ' | By ' . MailLink($info) . ' | ' . SiteLink($info) . '<br>Repo: ' . $h($repo);
        return;
    }
    $info = ModuleXMLTags(null, repo_browser_helper::Fetch(repo_browser_helper::Base($repo) . '/' . $module . '.xml'));
    if (!$info) {
        echo '<span class="label label-danger">Module not installed. No information available.</span><br>Repo: ' . $h($repo);
        return;
    }
    $ostype = '';
    switch (strtoupper(isset($info['ostype']) ? $info['ostype'] : '')) {
        case 'WIN':
            $ostype = '<br><img src="modules/repo_browser/assets/win.png" width="16" height="16"> This module is for Windows operating systems ONLY!';
            break;
        case 'LIN':
            $ostype = '<br><img src="modules/repo_browser/assets/lin.png" width="16" height="16"> This module is for Linux operating systems ONLY!';
            break;
        case 'ALL':
            $ostype = '<br><img src="modules/repo_browser/assets/all.png" width="16" height="16"> This module is for all operating systems.';
            break;
    }
    echo '<span class="label label-danger">Module not installed.</span><br>'
        . $h(isset($info['name']) ? $info['name'] : '')
        . (isset($info['desc']) ? '<p>' . $h($info['desc']) . '</p>' : '')
        . (isset($info['latestversion']) ? 'Version ' . $h($info['latestversion']) . ' | ' : '')
        . (isset($info['authorname']) ? 'By ' . MailLink($info) . ' | ' : '')
        . SiteLink($info) . '<br>Repo: ' . $h($repo) . $ostype;
}

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
        $line .= '<div class="znotice alert alert-block alert-info"><h2>' . ui_status::Warn('Warning') . ' Version Mismatch!</h2>'
            . '<p>Sentora thinks that ' . $h($module) . ' is version <b>' . $h($installed) . '</b>, but the installed module file says it is version <b>' . $h($local['version']) . '</b>.</p>'
            . '<p>This usually means the module developer did not update the version number in the database when the module was updated. If you just upgraded this module, Repo Browser can correct the number.</p>'
            . '<p><button class="button-loader btn btn-primary" type="submit" name="inUpdateVersionDB" value="' . $h($local['version'] . ':::' . $module . ':::' . $installed) . '">FIX VERSION NUMBER</button></p></div>';
    }
    $line .= '<div class="znotice alert alert-block alert-info">' . ui_status::Warn('Warning') . ' <b>There is a new version available for '
        . $h($module) . ': version ' . $h($latest) . '</b><br>'
        . (isset($info['updatedesc']) ? '<br><strong>Update Information:</strong><br>' . $h($info['updatedesc']) . '<br>' : '')
        . '<br><button class="button-loader btn btn-primary" type="submit" name="inUpdateModule" value="' . $h($repo . ':::' . $module) . '">Update Module</button></div>';
    echo $line;
}

function AjaxReturnDownloads($full)
{
    $split = repo_browser_helper::SplitFull($full);
    if (!$split || !repo_browser_helper::KnownRepo($split[0])) {
        return;
    }
    list($repo, $module) = $split;
    $list = repo_browser_helper::Fetch(repo_browser_helper::Base($repo) . '/repostatslist');
    if ($list !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $list) as $row) {
            $cols = preg_split('/\s+/', trim($row));
            if ($cols[0] === $module && isset($cols[1]) && ctype_digit($cols[1])) {
                echo number_format((int) $cols[1]) . ' downloads';
                return;
            }
        }
        return;
    }
    $json = json_decode(repo_browser_helper::Fetch(repo_browser_helper::Base($repo) . '/repostats.php?json=true'), true);
    if (is_array($json)) {
        foreach ($json as $stat) {
            if (is_array($stat) && isset($stat['package'], $stat['downloads']) && $stat['package'] === $module) {
                echo number_format((int) $stat['downloads']) . ' downloads';
                return;
            }
        }
    }
}

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

function SiteLink($info)
{
    $url = isset($info['authorurl']) ? (string) $info['authorurl'] : '';
    if (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
        return '<a href="' . repo_browser_helper::h($url) . '" target="_blank" rel="noopener noreferrer">Visit Author Site</a>';
    }
    return '';
}

/* Tags of a module.xml (local file) or update xml (downloaded text) */
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
