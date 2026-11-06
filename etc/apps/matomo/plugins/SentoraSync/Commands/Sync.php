<?php

/**
 * sentora:sync --spec=<file.json>
 *
 * Makes Matomo match the panel. The spec (written by the Sentora daemon) lists:
 *   sites:    [{ "domain": "example.com", "idsite": 0 or the known id }]
 *   accounts: [{ "login": "sentora_bob", "email": "...", "domains": ["example.com", ...] }]
 *   delete:   [idsite, ...]   (domains removed from the panel)
 *   importer: "sentora_importer"  (super user for the log import; token printed once)
 * and prints JSON: { "sites": { domain: idsite }, "tokens": { login: token (new users only) } }.
 *
 * Users are view-only on exactly the sites in their list. A new user gets a random
 * password nobody knows and a view token the panel uses to open Matomo for them.
 */

namespace Piwik\Plugins\SentoraSync\Commands;

use Piwik\Access;
use Piwik\Auth\Password;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\SitesManager\API as SitesAPI;
use Piwik\Plugins\UsersManager\Model as UsersModel;
use Piwik\Plugins\UsersManager\UsersManager;

class Sync extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('sentora:sync');
        $this->setDescription('Sync Matomo sites, users and access with the Sentora control panel');
        $this->addRequiredValueOption('spec', null, 'JSON file written by the Sentora daemon');
    }

    protected function doExecute(): int
    {
        $file = (string) $this->getInput()->getOption('spec');
        $spec = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($spec)) {
            $this->getOutput()->writeln('{"error":"no spec"}');
            return self::FAILURE;
        }
        $result = array('sites' => array(), 'tokens' => array());
        Access::doAsSuperUser(function () use ($spec, &$result) {
            $sites = SitesAPI::getInstance();
            $users = new UsersModel();
            $known = array();
            foreach ($sites->getAllSites() as $s) {
                $known[(int) $s['idsite']] = $s['name'];
            }
            // Sites
            foreach ((array) ($spec['sites'] ?? array()) as $site) {
                $domain = (string) ($site['domain'] ?? '');
                if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $domain)) {
                    continue;
                }
                $id = (int) ($site['idsite'] ?? 0);
                if ($id <= 0 || !isset($known[$id])) {
                    $id = (int) array_search($domain, $known, true);
                }
                if ($id <= 0) {
                    $id = (int) $sites->addSite($domain, array('http://' . $domain, 'https://' . $domain, 'http://www.' . $domain, 'https://www.' . $domain));
                    $known[$id] = $domain;
                }
                $result['sites'][$domain] = $id;
            }
            foreach ((array) ($spec['delete'] ?? array()) as $id) {
                if (isset($known[(int) $id])) {
                    $sites->deleteSite((int) $id);
                }
            }
            // Users: view access on exactly their own sites
            $password = StaticContainer::get(Password::class);
            foreach ((array) ($spec['accounts'] ?? array()) as $acc) {
                $login = (string) ($acc['login'] ?? '');
                if (!preg_match('/^sentora_[A-Za-z0-9_-]{1,64}$/', $login)) {
                    continue;
                }
                if (!$users->userExists($login)) {
                    $plain = bin2hex(random_bytes(24));
                    $users->addUser($login, $password->hash(UsersManager::getPasswordHash($plain)), (string) $acc['email'], Date::now()->getDatetime());
                    $token = $users->generateRandomTokenAuth();
                    $users->addTokenAuth($login, $token, 'Sentora panel', Date::now()->getDatetime());
                    $result['tokens'][$login] = $token;
                }
                $want = array();
                foreach ((array) ($acc['domains'] ?? array()) as $d) {
                    if (isset($result['sites'][$d])) {
                        $want[] = (int) $result['sites'][$d];
                    }
                }
                $users->deleteUserAccess($login);
                if (count($want)) {
                    $users->addUserAccess($login, 'view', $want);
                }
            }
            // The importer: a super user that only the daemon has a token for (log
            // import sends visits with their original times, which needs super user)
            $imp = (string) ($spec['importer'] ?? '');
            if (preg_match('/^sentora_[A-Za-z0-9_-]{1,64}$/', $imp)) {
                $new = !$users->userExists($imp);
                if ($new) {
                    $users->addUser($imp, $password->hash(UsersManager::getPasswordHash(bin2hex(random_bytes(24)))), 'matomo-importer@localhost.localdomain', Date::now()->getDatetime());
                    $users->setSuperUserAccess($imp, true);
                }
                // A new token when the user is new, or when the daemon lost its copy
                if ($new || !empty($spec['importer_new_token'])) {
                    $users->deleteAllTokensForUser($imp);
                    $token = $users->generateRandomTokenAuth();
                    $users->addTokenAuth($imp, $token, 'Sentora log import', Date::now()->getDatetime());
                    $result['tokens'][$imp] = $token;
                }
            }
            // Accounts removed from the panel
            foreach ((array) ($spec['delete_users'] ?? array()) as $login) {
                if (preg_match('/^sentora_[A-Za-z0-9_-]{1,64}$/', (string) $login) && $users->userExists($login)) {
                    $users->deleteUser($login);
                }
            }
        });
        $this->getOutput()->writeln(json_encode(array('sites' => (object) $result['sites'], 'tokens' => (object) $result['tokens'])));
        return self::SUCCESS;
    }

}
