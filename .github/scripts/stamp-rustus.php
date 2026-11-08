<?php
/**
 * Keep a line in the header comment of every PHP file that is not as Sentora 2.0.2 shipped it:
 *
 *     * Updated by RusTus, 2026-11-08    (a file Sentora 2.0.2 had, changed since)
 *     * Written by RusTus, 2026-11-08    (a file Sentora 2.0.2 did not have)
 *
 * The date is the file's last change in git (today for a change not committed yet); commits that only
 * changed these lines ("Headers: ...") do not count. A file back as Sentora shipped it loses the line.
 * Bundled third-party code (etc/apps, etc/lib, vendor folders) is left alone.
 *
 * Run from the repository root before a release commit:
 *     php .github/scripts/stamp-rustus.php          (writes the changes)
 *     php .github/scripts/stamp-rustus.php --check  (lists what would change, changes nothing)
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

// Sentora 2.0.2 as this repository started from it
const BASE = 'd88af4cb0c84040318f9aa00f7e36dfb122103ba';
const LINE = '/^([ \t]*\*[ \t]*)(Updated|Written) by RusTus, \d{4}-\d{2}-\d{2}(?=[ \t]*\r?$)/m';
// the same line with its line break, to take it out
const LINE_NL = '/^[ \t]*\*[ \t]*(Updated|Written) by RusTus, \d{4}-\d{2}-\d{2}[ \t]*\r?\n/m';

$check = in_array('--check', $argv, true);
chdir(trim((string) shell_exec('git rev-parse --show-toplevel')));

/** @return string[] */
function git_lines($cmd)
{
    return array_values(array_filter(explode("\n", (string) shell_exec($cmd))));
}

$inBase = array_flip(git_lines('git ls-tree -r --name-only ' . BASE));
$files = array();
foreach (git_lines('git ls-files') as $f) {
    if (preg_match('#^(etc/apps/|etc/lib/|.*/vendor/|vendor/|\.github/)#', $f) || !is_file($f)) {
        continue;
    }
    // PHP files, and PHP scripts without an extension (bin/)
    if (!preg_match('/\.php$/', $f) && !(strpos($f, 'bin/') === 0 && strpos((string) file_get_contents($f, false, null, 0, 200), 'php') !== false && strpos((string) file_get_contents($f, false, null, 0, 200), '<?php') !== false)) {
        continue;
    }
    $files[] = $f;
}

$changed = 0;
foreach ($files as $f) {
    $text = (string) file_get_contents($f);
    if (isset($inBase[$f])) {
        // compared without our own line, so the line itself does not count as a change
        $orig = (string) shell_exec('git show ' . BASE . ':' . escapeshellarg($f) . ' 2>/dev/null');
        $same = preg_replace(LINE_NL, '', str_replace("\r\n", "\n", $text)) === str_replace("\r\n", "\n", $orig);
        $word = 'Updated';
    } else {
        $same = false;
        $word = 'Written';
    }
    if (!$same) {
        // changed since the last commit, apart from this line? then today, else the last commit's date
        // (only lines other than this one and the blank comment line before it count)
        $dirty = false;
        foreach (explode("\n", (string) shell_exec('git diff -U0 HEAD -- ' . escapeshellarg($f))) as $d) {
            if (preg_match('/^[+-](?![+-]{2} )/', $d) && !preg_match('/^[+-][ \t]*\*[ \t]*((Updated|Written) by RusTus, \d{4}-\d{2}-\d{2})?[ \t\r]*$/', $d)) {
                $dirty = true;
                break;
            }
        }
        $date = $dirty ? date('Y-m-d') : trim((string) shell_exec('git log -1 --format=%ad --date=short --invert-grep --grep=' . escapeshellarg('^Headers: ') . ' -- ' . escapeshellarg($f)));
        $want = $word . ' by RusTus, ' . ($date !== '' ? $date : date('Y-m-d'));
    }
    $new = $text;
    if ($same) {
        $new = preg_replace(LINE_NL, '', $text, 1);
    } elseif (preg_match(LINE, $text, $m)) {
        $new = preg_replace(LINE, '${1}' . $want, $text, 1);
    } elseif (preg_match('#/\*\*.*?\*/#s', $text, $m, PREG_OFFSET_CAPTURE)) {
        // the first doc comment: the line goes last in it, after a blank comment line
        $block = $m[0][0];
        $eol = strpos($block, "\r\n") !== false ? "\r\n" : "\n";   // the file's own line endings
        $end = strrpos($block, '*/');
        $prefix = preg_match('/\n([ \t]*)\*\/$/', $block, $p) ? $p[1] . '* ' : ' * ';
        $before = rtrim(substr($block, 0, $end), " \t");
        $before = substr($before, -strlen($eol)) === $eol ? substr($before, 0, -strlen($eol)) : $before;
        $sep = preg_match('/\n[ \t]*\*\r?$/', $before) ? '' : $eol . rtrim($prefix);
        $block2 = $before . $sep . $eol . $prefix . $want . $eol . ($p[1] ?? ' ') . '*/';
        $new = substr($text, 0, $m[0][1]) . $block2 . substr($text, $m[0][1] + strlen($block));
    } else {
        // no doc comment at all: a short one after the opening tag
        $new = preg_replace('/<\?php[ \t]*\n/', "<?php\n/**\n * " . $want . "\n */\n", $text, 1);
    }
    if ($new !== $text) {
        $changed++;
        echo ($check ? 'would change: ' : 'changed: ') . $f . '  (' . ($same ? 'line removed' : $want) . ")\n";
        if (!$check) {
            file_put_contents($f, $new);
        }
    }
}
echo $changed . " file(s) " . ($check ? 'would change' : 'changed') . " of " . count($files) . "\n";
