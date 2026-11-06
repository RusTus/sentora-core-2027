<?php

/**
 * Apache Status: turns Apache's mod_status pages into one plain array.
 *
 * Reads the machine-readable summary (server-status?auto) and the tables of the HTML
 * page (one row per worker slot, and one per process on the event MPM). Columns are
 * found by their header names, so a column Apache adds or moves does not shift the
 * others. Pure functions: no I/O, so they can be tested on saved pages.
 *
 * Left out on purpose, because Server Monitor already shows them: the system load
 * averages (Load1/5/15) and the current time.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Parse server-status?auto ("Key: value" lines).
 *
 * @param string $text The ?auto page.
 * @return array<string, string> Key => value, as Apache wrote them.
 */
function apst_parse_auto($text)
{
    $out = array();
    foreach (preg_split('/\R/', (string) $text) as $line) {
        $p = strpos($line, ':');
        if ($p !== false && $p > 0) {
            $out[trim(substr($line, 0, $p))] = trim(substr($line, $p + 1));
        }
    }
    return $out;
}

/**
 * The tables of the HTML server-status page, each as a list of rows keyed by header.
 *
 * A table whose header has two rows (the event MPM's process table: groups such as
 * "Connections" over "total accepting") is named by the second row's labels, which are
 * all different. The group widths are not used: Apache marks "Threads" as two columns
 * wide but writes three (busy, graceful, idle), which would shift every later column.
 *
 * @param string $html The HTML server-status page.
 * @return array<int, array{head: string[], rows: array<int, array<string, string>>}> One entry per table.
 */
function apst_html_tables($html)
{
    $tables = array();
    if (trim((string) $html) === '') {
        return $tables;
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    foreach ($doc->getElementsByTagName('table') as $table) {
        $trs = array();
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = array();
            foreach ($tr->childNodes as $c) {
                if ($c instanceof DOMElement && ($c->tagName === 'th' || $c->tagName === 'td')) {
                    $cells[] = array('text' => trim(preg_replace('/\s+/', ' ', $c->textContent)), 'th' => $c->tagName === 'th',
                        'tall' => (int) $c->getAttribute('rowspan') >= 2);
                }
            }
            if ($cells) {
                $trs[] = $cells;
            }
        }
        if (!$trs) {
            continue;
        }
        // header rows: the leading rows made only of <th>
        $headRows = array();
        while ($trs && !array_filter($trs[0], function ($c) { return !$c['th']; })) {
            $headRows[] = array_shift($trs);
        }
        $head = array();
        if (count($headRows) >= 2) {
            // first row: full-height columns (rowspan) and group headers; the groups'
            // places are filled, in order, by every label of the second row
            $subsUsed = false;
            foreach ($headRows[0] as $g) {
                if ($g['tall']) {
                    $head[] = $g['text'];
                } elseif (!$subsUsed) {
                    foreach ($headRows[1] as $sub) {
                        $head[] = $sub['text'];
                    }
                    $subsUsed = true;
                }
            }
        } elseif ($headRows) {
            foreach ($headRows[0] as $g) {
                $head[] = $g['text'];
            }
        }
        $rows = array();
        foreach ($trs as $cells) {
            $row = array();
            foreach ($cells as $i => $c) {
                $row[isset($head[$i]) ? $head[$i] : (string) $i] = $c['text'];
            }
            $rows[] = $row;
        }
        $tables[] = array('head' => $head, 'rows' => $rows);
    }
    return $tables;
}

/**
 * Everything the Apache Status page shows, from the two status pages.
 *
 * @param string $auto The server-status?auto page.
 * @param string $html The HTML server-status page.
 * @param string $selfPath Requests for this path (the panel's own status reads) are left out of the request lists.
 * @return array<string, mixed> 'server', 'totals', 'workers', 'connections', 'processes', 'requests' and 'raw'.
 */
function apst_status($auto, $html, $selfPath = '/server-status')
{
    $a = apst_parse_auto($auto);
    $num = function ($k) use ($a) { return isset($a[$k]) && is_numeric($a[$k]) ? $a[$k] + 0 : null; };
    $str = function ($k) use ($a) { return isset($a[$k]) ? (string) $a[$k] : ''; };
    $sb = $str('Scoreboard');
    $counts = array();
    foreach (count_chars($sb, 1) as $ch => $n) {
        $counts[chr($ch)] = $n;
    }
    $out = array(
        'server' => array(
            'version' => $str('ServerVersion'), 'mpm' => $str('ServerMPM'), 'built' => $str('Server Built'),
            'restart' => $str('RestartTime'), 'uptime' => $num('ServerUptimeSeconds'),
            'configGeneration' => $num('ParentServerConfigGeneration'), 'mpmGeneration' => $num('ParentServerMPMGeneration'),
        ),
        'totals' => array(
            'accesses' => $num('Total Accesses'), 'kbytes' => $num('Total kBytes'), 'durationMs' => $num('Total Duration'),
            'reqPerSec' => $num('ReqPerSec'), 'bytesPerSec' => $num('BytesPerSec'), 'bytesPerReq' => $num('BytesPerReq'),
            'msPerReq' => $num('DurationPerReq'),
            'cpu' => array('user' => $num('CPUUser'), 'system' => $num('CPUSystem'), 'childrenUser' => $num('CPUChildrenUser'),
                'childrenSystem' => $num('CPUChildrenSystem'), 'load' => $num('CPULoad')),
        ),
        'workers' => array(
            'busy' => $num('BusyWorkers'), 'idle' => $num('IdleWorkers'), 'graceful' => $num('GracefulWorkers'),
            'processes' => $num('Processes'), 'stopping' => $num('Stopping'),
            'slots' => strlen($sb), 'scoreboard' => $sb, 'states' => $counts,
        ),
        'connections' => array(
            'total' => $num('ConnsTotal'), 'writing' => $num('ConnsAsyncWriting'),
            'keepAlive' => $num('ConnsAsyncKeepAlive'), 'closing' => $num('ConnsAsyncClosing'),
        ),
        'processes' => array(),
        'requests' => array(),
        // the summary as Apache wrote it, minus what Server Monitor shows
        'raw' => trim(preg_replace('/^(Load1|Load5|Load15|CurrentTime):.*\R?/m', '', (string) $auto)),
    );
    foreach (apst_html_tables($html) as $t) {
        if (in_array('Srv', $t['head'], true) && in_array('Request', $t['head'], true)) {
            foreach ($t['rows'] as $r) {
                $g = function ($k) use ($r) { return isset($r[$k]) ? $r[$k] : ''; };
                $req = $g('Request');
                $parts = explode(' ', $req, 3);
                $uri = isset($parts[1]) ? $parts[1] : '';
                if ($selfPath !== '' && strpos($uri, $selfPath) === 0) {
                    continue;
                }
                $acc = explode('/', $g('Acc'));
                $out['requests'][] = array(
                    'srv' => $g('Srv'), 'pid' => $g('PID'),
                    'acc' => array('conn' => (int) ($acc[0] ?? 0), 'child' => (int) ($acc[1] ?? 0), 'slot' => (int) ($acc[2] ?? 0)),
                    'mode' => $g('M'), 'cpu' => (float) $g('CPU'), 'ss' => (int) $g('SS'), 'req' => (int) $g('Req'),
                    'dur' => (int) $g('Dur'), 'connKb' => (float) $g('Conn'), 'childMb' => (float) $g('Child'), 'slotMb' => (float) $g('Slot'),
                    'client' => $g('Client'), 'protocol' => $g('Protocol'), 'vhost' => $g('VHost'),
                    'method' => $req !== '' ? $parts[0] : '', 'uri' => $uri, 'request' => $req,
                );
            }
        } elseif (in_array('PID', $t['head'], true) && in_array('Stopping', $t['head'], true)) {
            foreach ($t['rows'] as $r) {
                if (!isset($r['Slot']) || !ctype_digit($r['Slot'])) {
                    continue; // the "Sum" row
                }
                $g = function ($k) use ($r) { return isset($r[$k]) ? $r[$k] : ''; };
                $out['processes'][] = array(
                    'slot' => (int) $r['Slot'], 'pid' => $g('PID'), 'stopping' => $g('Stopping'),
                    'connections' => (int) $g('total'), 'accepting' => $g('accepting'),
                    'busy' => (int) $g('busy'), 'graceful' => (int) $g('graceful'), 'idle' => (int) $g('idle'),
                    'writing' => (int) $g('writing'), 'keepAlive' => (int) $g('keep-alive'), 'closing' => (int) $g('closing'),
                );
            }
        }
    }
    return $out;
}
