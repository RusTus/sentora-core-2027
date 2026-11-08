<?php

/**
 * PHP Info: turns the HTML of phpinfo() into one plain array.
 *
 * Pure function: no I/O, so it can be tested on a saved page.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-06
 */

/**
 * Parse the HTML phpinfo() page.
 *
 * @param string $html The page phpinfo() printed.
 * @return array{version: string, general: array<int, array{0: string, 1: string}>, modules: array<string, array{info: array<int, array{0: string, 1: string}>, directives: array<int, array{0: string, 1: string, 2: string}>}>, environment: array<int, array{0: string, 1: string}>, variables: array<int, array{0: string, 1: string}>}
 *     'general' holds the first table (System, Build Date ...); each module its information rows and
 *     its directives (name, local value, master value); 'environment' and 'variables' the two sections of those names.
 */
function phpi_parse($html)
{
    $out = array('version' => PHP_VERSION, 'general' => array(), 'modules' => array(), 'environment' => array(), 'variables' => array());
    if (trim((string) $html) === '') {
        return $out;
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $text = function ($n) { return trim(preg_replace('/\s+/u', ' ', $n->textContent)); };

    $section = 'general';   // general, module, environment, variables, skip
    $module = '';
    $xp = new DOMXPath($doc);
    foreach ($xp->query('//body//h1 | //body//h2 | //body//table') as $node) {
        $tag = strtolower($node->nodeName);
        if ($tag === 'h1') {
            $title = $text($node);
            if (stripos($title, 'PHP Version') === 0) {
                $out['version'] = trim(substr($title, 11));
                $section = 'general';
            } elseif ($title === 'Configuration') {
                $section = 'skip';
            } else {
                $section = 'skip';   // PHP Credits and the like
            }
            continue;
        }
        if ($tag === 'h2') {
            $title = $text($node);
            if ($title === 'Environment') {
                $section = 'environment';
            } elseif ($title === 'PHP Variables') {
                $section = 'variables';
            } elseif (in_array($title, array('Additional Modules', 'PHP License', 'PHP Credits'), true)) {
                $section = 'skip';
            } else {
                $section = 'module';
                $module = $title;
                $out['modules'][$module] = array('info' => array(), 'directives' => array());
            }
            continue;
        }
        if ($section === 'skip') {
            continue;
        }
        foreach ($node->getElementsByTagName('tr') as $tr) {
            if ($tr->getAttribute('class') === 'h') {
                continue;   // headings and the logo row
            }
            $cells = array();
            foreach ($tr->childNodes as $c) {
                if ($c instanceof DOMElement && ($c->tagName === 'td' || $c->tagName === 'th')) {
                    $cells[] = $text($c);
                }
            }
            if (!$cells) {
                continue;
            }
            if ($section === 'module' && count($cells) >= 3) {
                $out['modules'][$module]['directives'][] = array($cells[0], $cells[1], $cells[2]);
            } elseif ($section === 'module') {
                $out['modules'][$module]['info'][] = array($cells[0], isset($cells[1]) ? $cells[1] : '');
            } else {
                $out[$section][] = array($cells[0], isset($cells[1]) ? $cells[1] : '');
            }
        }
    }
    return $out;
}
