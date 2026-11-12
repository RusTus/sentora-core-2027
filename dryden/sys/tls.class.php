<?php

/**
 * Core class: the TLS settings of the panel's and the hosted sites' HTTPS, in one place.
 *
 * TLS 1.2 and 1.3 only, and Mozilla's "intermediate" ciphers (forward secrecy, AES-GCM or
 * ChaCha20; no CBC, no plain RSA key exchange), as in Sentora's httpd.conf. The browser's order
 * is used (SSLHonorCipherOrder off): every cipher in the list is strong, and the browser knows
 * whether it has AES in hardware.
 *
 * Sencrypt stores these lines with each domain (x_vhosts.vh_ssl_tx). Upgrade() brings the lines
 * older Sentora versions wrote there to today's, and leaves alone any line an administrator wrote.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-12
 */
class sys_tls
{
    const PROTOCOL = 'SSLProtocol -all +TLSv1.2 +TLSv1.3';
    const ORDER = 'SSLHonorCipherOrder off';
    const CIPHERS = 'SSLCipherSuite "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:DHE-RSA-CHACHA20-POLY1305"';

    /**
     * The three lines, each ending in a new line.
     *
     * @return string
     */
    static function Lines()
    {
        $nl = fs_filehandler::NewLine();
        return self::PROTOCOL . $nl . self::ORDER . $nl . self::CIPHERS . $nl;
    }

    /**
     * The lines older Sentora versions wrote, exactly (other text is never changed).
     *
     * @return array<string, string> old line => today's line
     */
    static function Old()
    {
        $c = 'SSLCipherSuite "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384"';
        return array(
            'SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1' => self::PROTOCOL,
            'SSLProtocol All -SSLv3 -TLSv1 -TLSv1.1' => self::PROTOCOL,
            'SSLProtocol All -SSLv2 -SSLv3 -TLSv1 -TLSv1.1' => self::PROTOCOL,
            'SSLHonorCipherOrder on' => self::ORDER,
            $c => self::CIPHERS,
            'SSLCipherSuite "EECDH+ECDSA+AESGCM EECDH+aRSA+AESGCM EECDH+ECDSA+SHA384 EECDH+ECDSA+SHA256 EECDH+aRSA+SHA384 EECDH+aRSA+SHA256 EECDH+AESGCM EECDH EDH+AESGCM EDH+aRSA HIGH !MEDIUM !LOW !aNULL !eNULL !LOW !RC4 !MD5 !EXP !PSK !SRP !DSS"' => self::CIPHERS,
        );
    }

    /**
     * Today's lines in place of older Sentora versions' in a domain's stored SSL settings.
     *
     * @param string $text
     * @return string
     */
    static function UpgradeText($text)
    {
        $old = self::Old();
        $lines = preg_split('/(\r?\n)/', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($lines as $i => $l) {
            $t = trim($l);
            if (isset($old[$t])) {
                $lines[$i] = substr($l, 0, strlen($l) - strlen(ltrim($l))) . $old[$t];
            }
        }
        return implode('', $lines);
    }

    /**
     * Bring every domain's stored SSL settings to today's lines (the daemon, once a run: only rows
     * that still have an old line are changed).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return int How many domains changed.
     */
    static function Upgrade()
    {
        global $zdbh;
        $n = 0;
        $rows = $zdbh->query("SELECT vh_id_pk, vh_ssl_tx FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_ssl_tx LIKE '%SSL%'")->fetchAll(PDO::FETCH_ASSOC);
        $set = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = :t WHERE vh_id_pk = :id");
        foreach ($rows as $r) {
            $new = self::UpgradeText((string) $r['vh_ssl_tx']);
            if ($new !== (string) $r['vh_ssl_tx']) {
                $set->execute(array(':t' => $new, ':id' => (int) $r['vh_id_pk']));
                $n++;
            }
        }
        return $n;
    }
}
