<?php

/**
 * Core class: the node framework. A node is a separate server that extends the panel (a Docker
 * node, a backup node, and whatever node types add-ons bring). Every node is installed and paired
 * the same way, so the person setting it up always does the same three things:
 *
 *  1. On the new server, run that node type's own installer. It asks for the panel's address,
 *     fetches the panel's public key for that node type and shows the panel's code (PanelCode):
 *     the person answers "y" only when it is the code shown on the panel's page. So the node is
 *     sure it talks to this panel (nothing in between can fake it without the person noticing).
 *  2. The installer sets the node up and ends with the node's key (NodeKey: a prefix of the node
 *     type, then four groups of four), made from the node's own key.
 *  3. On the panel, "Add a ... node": the person pastes that key. The panel checks it against the
 *     key the node really answers with (for an SSH node: SshKeyCode of what it scans), and only
 *     then trusts that node, for good (it pins the node's key; another key is refused later).
 *
 * The codes are base32 without the letters people mix up (no I, O, 0 or 1): the panel's code is
 * 40 bits (it is compared by eye, against a key the node fetched itself), a node's key 80 bits (it
 * is the only thing that ties a node to its key, so it must be impossible to make a matching key).
 * The same codes are easy to make in a shell, so an installer needs nothing but coreutils
 * (NODES.md in the installers repository has the shell lines and a template installer).
 *
 * Add-ons use this class behind class_exists('sys_nodes') (it is in the core since 2027.86).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-13
 */
class sys_nodes
{
    /** The letters codes are made of: base32 without I, O, 0 and 1. */
    const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Base32 of binary data (5 bits a letter, the last one padded with zero bits).
     *
     * @param string $bin
     * @return string
     */
    static function Base32($bin)
    {
        $bits = '';
        foreach (str_split((string) $bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    /**
     * The panel's code for one node type: the first 8 letters of base32(sha256(type "|" public
     * key)), as XXXX-XXXX. Shown on the panel's page and by the node's installer, to be compared.
     *
     * In a shell: printf '%s|%s' "$TYPE" "$PUBKEY" | sha256sum, then the first 40 bits in base32.
     *
     * @param string $type The node type, for example 'sentora-backup'.
     * @param string $publicKey The panel's public key for that node type (as the node fetches it).
     * @return string
     */
    static function PanelCode($type, $publicKey)
    {
        $b = self::Base32(hash('sha256', $type . '|' . trim((string) $publicKey), true));
        return substr($b, 0, 4) . '-' . substr($b, 4, 4);
    }

    /**
     * The code of an SSH public key ("ssh-ed25519 AAAA... comment"): the first 16 letters of
     * base32(sha256(the key's blob)), the same hash SSH's own fingerprint is made of.
     *
     * @param string $line
     * @return string 16 letters, or '' when it is not a public key line
     */
    static function SshKeyCode($line)
    {
        $blob = self::SshBlob($line);
        return $blob === '' ? '' : substr(self::Base32(hash('sha256', $blob, true)), 0, 16);
    }

    /**
     * SSH's own fingerprint of a public key line: SHA256:<base64 without padding>.
     *
     * @param string $line
     * @return string or ''
     */
    static function SshFingerprint($line)
    {
        $blob = self::SshBlob($line);
        return $blob === '' ? '' : 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * The key itself (decoded) from a public key line, or ''.
     *
     * @param string $line "type base64 [comment]", or a line of ssh-keyscan ("host type base64")
     * @return string
     */
    static function SshBlob($line)
    {
        $p = preg_split('/\s+/', trim((string) $line));
        foreach ($p as $i => $w) {
            if (preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521))$/', $w) && isset($p[$i + 1]) && preg_match('#^[A-Za-z0-9+/]{40,1000}={0,3}$#', $p[$i + 1])) {
                $b = base64_decode($p[$i + 1], true);
                return $b === false ? '' : $b;
            }
        }
        return '';
    }

    /**
     * A node's key as shown to people: PREFIX-XXXX-XXXX-XXXX-XXXX.
     *
     * @param string $prefix The node type's prefix, 3 capitals or digits (SB1: a Sentora backup node).
     * @param string $code 16 letters (SshKeyCode)
     * @return string
     */
    static function NodeKey($prefix, $code)
    {
        return $prefix . '-' . implode('-', str_split($code, 4));
    }

    /**
     * A node's key as someone typed or pasted it back to its 16 letters, or false. Spaces and
     * small letters are fine; the prefix must be the node type's.
     *
     * @param string $prefix
     * @param string $typed
     * @return string|false
     */
    static function ParseKey($prefix, $typed)
    {
        $k = strtoupper(preg_replace('/\s+/', '', (string) $typed));
        if (!preg_match('/^' . preg_quote($prefix, '/') . '((-[A-HJ-NP-Z2-9]{4}){4})$/', $k, $m)) {
            return false;
        }
        return str_replace('-', '', $m[1]);
    }

    /**
     * The SSH key a server answers with (ed25519), as ssh-keyscan prints it; root only (the
     * daemon). '' when it does not answer.
     *
     * @param string $host
     * @param int $port
     * @return string
     */
    static function ScanSshKey($host, $port = 22)
    {
        if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,252}[A-Za-z0-9])?$|^[0-9a-fA-F:]{2,39}$/', (string) $host) || (int) $port < 1 || (int) $port > 65535) {
            return '';
        }
        $out = array();
        exec('ssh-keyscan -T 15 -t ed25519 -p ' . (int) $port . ' ' . escapeshellarg((string) $host) . ' 2> /dev/null', $out);
        foreach ($out as $l) {
            if ($l !== '' && $l[0] !== '#' && self::SshBlob($l) !== '') {
                return $l;
            }
        }
        return '';
    }
}
