<?php

/**
 * FTP Accounts: the API's FTP requests. The account must be the key's own or one it may reach
 * (ws_xmws::Reach()); an FTP account is only changed or deleted when its account is. The checks the
 * page makes before it acts are made here too: the package's limit, the user name and password
 * rules (in the module's own functions), and the home folder staying inside the account's own
 * folder (FTP Accounts' HomeFolder(): real path, no ../, no links out).
 *
 * The module's functions write ProFTPd's own tables through the FTP server file they include, which
 * they find by the page's module name, and some check the FTP account against the logged-in account:
 * each call runs with the module named and the FTP account's owner as the logged-in account (AsOwner()),
 * in memory only, for that call.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class webservice extends ws_xmws
{

    /**
     * GetFtpAccounts: <uid>. Each with its <id>, <username>, <folder> (in the account's home) and
     * <access> (RW full, RO read only, WO upload only). Never a password.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function GetFtpAccounts()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $q = $zdbh->prepare("SELECT ft_id_pk, ft_user_vc, ft_directory_vc, ft_access_vc, ft_created_ts FROM x_ftpaccounts WHERE ft_acc_fk = :u AND ft_deleted_ts IS NULL ORDER BY ft_user_vc");
        $q->execute(array(':u' => $uid));
        $xml = "\n";
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $xml .= self::NewXMLContentSection('ftpaccount', array('id' => (int) $r['ft_id_pk'], 'username' => (string) $r['ft_user_vc'],
                'folder' => (string) $r['ft_directory_vc'], 'access' => (string) $r['ft_access_vc'], 'created' => (int) $r['ft_created_ts']));
        }
        return $this->Answer($xml);
    }

    /**
     * CreateFtpAccount: <uid>, <username> (the panel puts the account's name and _ before it), <password>
     * (the panel's rules: its minimum length, a digit, a lower-case and an upper-case letter),
     * <access> (RW, RO or WO; RW by default), and <folder>: empty for a new folder named after the FTP
     * user, else an existing folder in the account's home (such as public_html/example_com).
     * Answers its <id> and full <username>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    public function CreateFtpAccount()
    {
        global $zdbh;
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        // the package's limit, as the FTP Accounts page checks it
        if (!ws_data::UnderLimit($uid, 'ftpaccounts')) {
            return self::Error('1107', 'The account\'s package allows no more FTP accounts');
        }
        $access = $this->Get('access') === null || $this->Get('access') === '' ? 'RW' : strtoupper((string) $this->Get('access'));
        if (!in_array($access, array('RW', 'RO', 'WO'), true)) {
            return self::Error('1107', 'access: RW (full), RO (read only) or WO (upload only)');
        }
        $name = (string) $this->Get('username');
        $folder = trim((string) $this->Get('folder'), '/');
        // 1: a new folder named after the FTP user; 2: a folder in the account's home (HomeFolder() checks it)
        $home = $folder === '' ? 1 : 2;
        $ok = self::AsOwner($uid, function () use ($uid, $name, $folder, $access, $home) {
            return module_controller::ExecuteCreateFTP($uid, $name, (string) $this->Get('password'), $folder, '', $access, $home);
        });
        if (!$ok) {
            return self::Error('1107', self::Why());
        }
        $full = ctrl_users::GetUserDetail($uid)['username'] . '_' . $name;
        $q = $zdbh->prepare("SELECT ft_id_pk FROM x_ftpaccounts WHERE ft_user_vc = :n AND ft_acc_fk = :u AND ft_deleted_ts IS NULL");
        $q->execute(array(':n' => $full, ':u' => $uid));
        $id = (int) $q->fetchColumn();
        self::$note = 'ftp account ' . $id;
        return $this->Answer(self::NewXMLTag('created', 'true') . self::NewXMLTag('id', $id) . self::NewXMLTag('username', $full));
    }

    /**
     * DeleteFtpAccount: <ftpid>.
     *
     * @return array
     */
    public function DeleteFtpAccount()
    {
        $id = $this->Get('ftpid');
        $owner = self::FtpOwner($id);
        if ($owner === 0) {
            return self::Error('1107', 'No such FTP account you may reach');
        }
        $ok = self::AsOwner($owner, function () use ($id, $owner) {
            return module_controller::ExecuteDeleteFTP((int) $id, $owner);
        });
        if (!$ok) {
            return self::Error('1107', 'The panel refused it');
        }
        return $this->Answer(self::NewXMLTag('id', (int) $id) . self::NewXMLTag('deleted', 'true'));
    }

    /**
     * SetFtpPassword: <ftpid>, <password> (the panel's rules, as for CreateFtpAccount).
     *
     * @return array
     */
    public function SetFtpPassword()
    {
        $id = $this->Get('ftpid');
        $owner = self::FtpOwner($id);
        if ($owner === 0) {
            return self::Error('1107', 'No such FTP account you may reach');
        }
        $pass = (string) $this->Get('password');
        $ok = self::AsOwner($owner, function () use ($id, $pass) {
            return module_controller::ExecuteResetPassword((int) $id, $pass);
        });
        if (!$ok) {
            return self::Error('1107', self::Why());
        }
        return $this->Answer(self::NewXMLTag('id', (int) $id) . self::NewXMLTag('reset', 'true'));
    }

    /**
     * The account an FTP account belongs to, when the key may reach it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param mixed $id The FTP account (x_ftpaccounts) ID as sent.
     * @return int The account, or 0.
     */
    private static function FtpOwner($id)
    {
        global $zdbh;
        if (!is_scalar($id) || !ctype_digit((string) $id)) {
            return 0;
        }
        $q = $zdbh->prepare("SELECT ft_acc_fk FROM x_ftpaccounts WHERE ft_id_pk = :id AND ft_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $id));
        $owner = $q->fetchColumn();
        return $owner === false ? 0 : self::Reach((string) (int) $owner);
    }

    /**
     * Run one of the module's functions as the page would for the FTP account's owner: the module
     * named (its functions find the FTP server file by it, which writes ProFTPd's tables) and the
     * owner as the logged-in account (their ownership checks). Only for this call: the request's own
     * controller comes back afterwards, whatever happens.
     *
     * @global runtime_controller $controller The request controller.
     * @param int $owner The account (already checked with Reach()).
     * @param callable $fn The call.
     * @return mixed What it returned.
     */
    private static function AsOwner($owner, callable $fn)
    {
        global $controller;
        $real = $controller;
        $controller = new class($real, (int) $owner) {
            /** @var runtime_controller The request's own controller. */
            private $real;
            /** @var int The account to be. */
            private $owner;

            /**
             * @param runtime_controller $real
             * @param int $owner
             */
            public function __construct($real, $owner)
            {
                $this->real = $real;
                $this->owner = $owner;
            }

            /**
             * The module's name and the owner; everything else as the request's own controller has it.
             *
             * @param string $type
             * @param string $name
             * @return mixed
             */
            public function GetControllerRequest($type, $name)
            {
                if ($type === 'URL' && $name === 'module') {
                    return 'ftp_management';
                }
                if ($type === 'USER' && $name === 'zpuid') {
                    return $this->owner;
                }
                return $this->real->GetControllerRequest($type, $name);
            }

            /**
             * Anything else: the request's own controller.
             *
             * @param string $m
             * @param array $a
             * @return mixed
             */
            public function __call($m, $a)
            {
                return call_user_func_array(array($this->real, $m), $a);
            }
        };
        try {
            return $fn();
        } finally {
            $controller = $real;
        }
    }

    /**
     * What the module's checks said when they refused.
     *
     * @return string
     */
    private static function Why()
    {
        $why = array('alreadyexists' => 'That FTP user name is taken', 'badname' => 'The user name may have letters, digits, - and _ only (up to 63 with the account\'s name)',
            'blank' => 'A user name and a password are needed', 'blankpassword' => 'A password is needed',
            'badpasswordlength' => 'The password is too short (at least ' . (int) module_controller::getMinPassLength() . ' characters)',
            'badpass' => 'The password needs a digit, a lower-case and an upper-case letter',
            'invalidPath' => 'folder: an existing folder inside the account\'s own home', 'error' => 'The panel refused it');
        foreach ($why as $flag => $text) {
            if (property_exists('module_controller', $flag) && !empty(module_controller::$$flag)) {
                return $text;
            }
        }
        return 'The panel refused it';
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
