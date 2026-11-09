<?php

/**
 * Backups: the API's backup requests. A backup is the account's websites and databases in one zip,
 * made in the background as the Backups page makes it (sys_backup); the key's reach decides which
 * accounts (ws_xmws::Reach()). Downloading stays on the Backups page (the files are large).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class webservice extends ws_xmws
{
    /**
     * StartBackup: <uid>. Starts a backup in the background; answers started, running (one is being
     * made) or recent (one was finished less than 2 minutes ago).
     *
     * @return array
     */
    public function StartBackup()
    {
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $r = sys_backup::StartBackground($uid);
        if ($r === 'failed') {
            return self::Error('1101', 'The backup could not be started (the server\'s logs say why)');
        }
        return $this->Answer(self::NewXMLTag('uid', $uid) . self::NewXMLTag('state', $r));
    }

    /**
     * GetBackups: <uid>. The latest backup's progress (state none, running, done or failed) and the
     * account's backup files (name, size in bytes, made).
     *
     * @return array
     */
    public function GetBackups()
    {
        $uid = self::Reach($this->Get('uid'));
        if ($uid === 0) {
            return self::Error('1107', 'No such account you may reach');
        }
        $p = sys_backup::Progress($uid);
        $xml = self::NewXMLContentSection('progress', array('state' => $p['state'], 'started' => $p['started'] ?? '',
            'finished' => $p['finished'] ?? '', 'message' => $p['message'] ?? ''));
        foreach (sys_backup::ListFiles((string) ctrl_users::GetUserDetail($uid)['username']) as $name => $f) {
            $xml .= self::NewXMLContentSection('backup', array('file' => $name, 'size' => $f['size'], 'made' => $f['mtime']));
        }
        return $this->Answer($xml);
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
