<?php

/**
 * Sencrypt SSL: the API's certificate requests. A certificate is the account's own (Let's Encrypt,
 * made as the Sencrypt page makes it, for one of the account's own domains or sub-domains); the
 * key's reach decides which accounts (ws_xmws::Reach()).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class webservice extends ws_xmws
{
    /**
     * GetCertificates: <uid>. Each of the account's domains and sub-domains with its certificate:
     * kind (letsencrypt, third_party or none), valid until, days left.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function GetCertificates()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $user = ctrl_users::GetUserDetail($uid);
        $q = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_acc_fk = :u AND vh_type_in IN (1, 2) AND vh_deleted_ts IS NULL ORDER BY vh_name_vc");
        $q->execute(array(':u' => $uid));
        $xml = "\n";
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $c = module_controller::CertOf((string) $user['username'], (string) $name);
            $xml .= self::NewXMLContentSection('certificate', array('domain' => $name, 'kind' => $c ? $c['kind'] : 'none',
                'until' => $c ? $c['until'] : '', 'daysleft' => $c && $c['days'] !== null ? $c['days'] : ''));
        }
        return $this->Answer($xml);
    }

    /**
     * RequestCertificate: <uid>, <domain> (one of that account's own domains or sub-domains). Gets a
     * Let's Encrypt certificate now (it takes up to a minute); the site uses it within 5 minutes.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function RequestCertificate()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $domain = strtolower((string) $this->Get('domain'));
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk = :u AND vh_name_vc = :d AND vh_type_in IN (1, 2) AND vh_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid, ':d' => $domain));
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain) || strpos($domain, '..') !== false || (int) $q->fetchColumn() === 0) {
            return self::Error('1107', 'domain is not one of the account\'s own domains or sub-domains');
        }
        // Sencrypt makes certificates for the account that asks (its folders, its email for Let's Encrypt)
        self::ActAs($uid);
        if (!module_controller::OwnsDomainName($domain)) {
            return self::Error('1107', 'domain is not one of the account\'s own domains or sub-domains');
        }
        $ok = module_controller::ExecuteMakeSSL($domain, (string) ctrl_users::GetUserDetail($uid)['username'], 'letsencrypt');
        if (!$ok || empty(module_controller::$okletsencrypt)) {
            $why = !empty(module_controller::$dnsInvalid) ? 'The domain has no address in DNS yet: add an A record pointing to this server'
                : (!empty(module_controller::$certFailedWhy) ? mb_substr(\Analogic\ACME\Lescript::explain((string) module_controller::$certFailedWhy), 0, 400) : 'Let\'s Encrypt did not issue the certificate');
            return self::Error('1107', $why);
        }
        return $this->Answer(self::NewXMLTag('domain', $domain) . self::NewXMLTag('issued', 'true')
            . (module_controller::$wwwNote ? self::NewXMLTag('note', (string) module_controller::$wwwNote) : ''));
    }

    /**
     * A done answer.
     *
     * @param string $content The XML content.
     * @return array
     */
    private function Answer($content)
    {
        $d = new runtime_dataobject();
        $d->addItemValue('response', '');
        $d->addItemValue('content', $content);
        return $d->getDataObject();
    }
}
