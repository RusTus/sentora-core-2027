<?php

/**
 * Repo Browser module (Admin): controller.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-12
 */

require_once dirname(__FILE__) . '/repohelper.php';

/**
 *
 * Sentora - repo browser zpanel plugin, written by RusTus: www.zpanelcp.com.
 *
 * Lists the module repositories and their packages and queues zppy commands
 * (install, remove, upgrade, repo add/remove) for the daemon, which runs them as root.
 */
 
class module_controller extends ctrl_module {

    /**
     * The warning icon in the page (ui_status, no image file) (template <@ WarnIcon @>).
     *
     * @return string The HTML.
     */
    static function getWarnIcon()
    {
        return ui_status::Warn('Warning');
    }


    /** @var bool|null True after a command was queued (shown by getResult()). */
    static $ok = NULL;
    /** @var array{0: int, 1: int}|null After "Update all": modules queued, modules left out. */
    static $updateall = NULL;
	/** @var string|null HTML: the queued command, shown by getZppyOut(). */
	static $zppyout1 = NULL;
	/** @var string|null HTML: more output for getZppyOut() (not set any more). */
	static $zppyout2 = NULL;
	/** @var bool|null True when the module to install is not in the repository. */
	static $modulenotfound = NULL;
	/** @var bool|null True when a zppy command or repository address was not valid or could not be queued. */
	static $zppycommanderror = NULL;
	/** @var bool|null True when the module or repository already has a job waiting. */
	static $alreadyqueued = NULL;
	/** @var bool|null True after a repository that could not be added was removed from the list. */
	static $repodismissed = NULL;
	/** @var bool|null True when the zppy command was empty. */
	static $zppycommandblank = NULL;
	/** @var bool|null True when the typed command started with "zppy". */
	static $zppycommandzppynotneeded = NULL;
	/** @var string|null The module just rated (shown by getResult()); '' when no stars were chosen. */
	static $rated = NULL;
	/** @var bool|null True after "Check all repositories now" (an update queued, or one already waiting). */
	static $checkqueued = NULL;
	/** @var bool|null ZPPY patch installed (not set any more). */
	static $zppypatchok = NULL;
	/** @var bool|null ZPPY patch failed (not set any more). */
	static $zppypatcherror = NULL;

    /**
     * The repositories: those in the list (checked live by ajax.php; a Retry button
     * appears if the check fails), those waiting to be added or removed (Queued), and
     * those the daemon could not add (Failed, with Retry and Remove).
     *
     * @return array<int, array<string, string>>|false One row per repository: 'line', 'statushtml', 'buttonshtml'; false when there are none.
     */
    static function ListRepositories() {
		$h = function ($s) { return repo_browser_helper::h($s); };
		$label = function ($class, $text, $title) use ($h) {
			return '<span class="label ' . $class . '" title="' . $h($title) . '">' . $h($text) . '</span>';
		};
		$queuedButton = '<button class="btn btn-default btn-sm" type="button" disabled="disabled" title="'
			. $h(ui_language::translate("Waiting for the daemon (this can take up to 5 minutes).")) . '">' . ui_language::translate("Queued") . '</button>';
		$jobs = repo_browser_helper::RepoJobs();
		$res = array();
		$listed = array();
		foreach (repo_browser_helper::Repos() as $repo) {
			if ($repo === 'packages.zpanelcp.com') {
				continue;
			}
			$listed[$repo] = true;
			if (isset($jobs[$repo]) && $jobs[$repo]['state'] === 'queued') {
				$status = $label('label-warning', ui_language::translate('QUEUED'), ui_language::translate('Removal queued (this can take up to 5 minutes).'));
				$buttons = $queuedButton;
			} else {
				$status = '<div class="controls" item_id="R:::' . $h($repo) . '"><span class="rb-wait" aria-label="' . $h(ui_language::translate('loading')) . '"></span></div>';
				$buttons = '<button class="btn btn-default btn-sm retry-repo" type="submit" name="inRetryRepo" value="' . $h($repo) . '" style="display:none">' . ui_language::translate('Retry') . '</button> '
					. '<button class="btn btn-default btn-sm rb-del" type="submit" name="inDeleteRepo" value="' . $h($repo) . '">' . ui_language::translate('Remove') . '</button>';
			}
			$res[$repo] = array('line' => $h($repo), 'statushtml' => $status, 'buttonshtml' => $buttons);
		}
		foreach ($jobs as $repo => $job) {
			if (isset($listed[$repo]) || $job['action'] !== 'add' || !repo_browser_helper::ValidRepo($repo)) {
				continue;
			}
			if ($job['state'] === 'queued') {
				$status = $label('label-warning', ui_language::translate('QUEUED'), ui_language::translate('Waiting to be added (this can take up to 5 minutes).'));
				$buttons = $queuedButton;
			} else {
				$status = $label('label-danger', ui_language::translate('FAILED'), ui_language::translate('The daemon could not add this repository; see Recent ZPPY commands.'));
				$buttons = '<button class="btn btn-default btn-sm" type="submit" name="inRetryRepo" value="' . $h($repo) . '">' . ui_language::translate('Retry') . '</button> '
					. '<button class="btn btn-default btn-sm rb-del" type="submit" name="inDismissRepo" value="' . $h($repo) . '">' . ui_language::translate('Remove') . '</button>';
			}
			$res[$repo] = array('line' => $h($repo), 'statushtml' => $status, 'buttonshtml' => $buttons);
		}
		if (!count($res)) {
			return false;
		}
		ksort($res);
		return array_values($res);
    }
	
    /**
     * The packages in etc/zppy-cache/package.list (not the core modules), with install state and author information.
     *
     * @global db_driver $zdbh The panel's database connection (unused).
     * @global runtime_controller $controller The request controller (unused).
     * @return array<int, array<string, mixed>>|false One row per package: 'modulename', 'buttonattr', 'reponame', 'description', 'buttonaction', 'buttoncss', 'authorurl', 'version', 'authoremail', 'authorname', 'installed', 'title', 'installedhtml', 'url'; false when there is no package list.
     */
    static function ListModules() {
        global $zdbh;
		global $controller;
		$modulelist = ctrl_options::GetSystemOption('sentora_root') . "etc/zppy-cache/package.list";
		if (file_exists($modulelist)){
       		$file_handle = fopen($modulelist, "r");
			$res = array();
			while (!feof($file_handle)) {
   				$line = trim(fgets($file_handle));
				if (!fs_director::CheckForEmptyValue($line)){
					$content = preg_split("/[\s,]+/", $line);
					if (isset($content[1]) && !fs_director::CheckForEmptyValue($content[1])){
						if (!repo_browser_helper::IsCoreModule($content[0]) && repo_browser_helper::ValidModule($content[0]) && repo_browser_helper::ValidRepo($content[1])) {
							$url = repo_browser_helper::Base($content[1]) . "/" . $content[0] . ".zpp";					
							$modinfo = self::InstalledModule($content[0]);
					        if ($modinfo) {
								$installed = "installed";
								$title = str_replace('%s', $content[0], ui_language::translate("%s is already installed on this server."));
								$info = ui_module::GetModuleXMLTags($modinfo['mo_folder_vc']);
								$description = repo_browser_helper::h($modinfo['mo_desc_tx']);
								$authorurl = $info['authorurl'];
								$version = $info['version'];
								$authoremail = $info['authoremail'];
								$authorname = $info['authorname'];
								$buttonaction = "Remove";
								$buttoncss = "btn btn-default btn-sm rb-del";
								$installedhtml = "<a href=\"./?module=" . $content[0] . "\" title=\"" . repo_browser_helper::h(ui_language::translate("Open the module")) . "\">" . $content[0] . "</a>";						
							} else {
								$installed = "notinstalled";
								$title = ui_language::translate("To install this module, click: INSTALL");
								$description = repo_browser_helper::h(ui_language::translate("No description available."));
								$authorurl = "";
								$version = ui_language::translate("not available");
								$authoremail = ui_language::translate("not available");
								$authorname = ui_language::translate("not available");
								$buttonaction = "Install";
								$buttoncss = "btn btn-primary btn-sm";
								$installedhtml = $content[0];
							} 
							// A job for this module is waiting for the daemon: no second one
							$buttonattr = '';
							$pending = repo_browser_helper::Pending($content[0]);
							if ($pending !== false) {
								$buttonaction = "Queued";
								$buttoncss = "btn btn-default btn-sm";
								$buttonattr = 'disabled="disabled"';
								$title = ui_language::translate($pending === 'remove' ? 'Removal queued: the daemon runs it within 5 minutes.' : ($pending === 'upgrade' ? 'Upgrade queued: the daemon runs it within 5 minutes.' : 'Installation queued: the daemon runs it within 5 minutes.'));
							}
								$ratehtml = $installed === 'installed' ? self::RateBox(trim($content[0]), trim($content[1])) : '';
	                		array_push($res, array('modulename'  => trim($content[0]),
										   'ratehtml'        => $ratehtml,
										   'buttonattr'      => $buttonattr,
                    				   	   'reponame'        => trim($content[1]),
										   'description'     => $description,
										   'buttonaction'    => $buttonaction,
										   'buttonlabel'     => repo_browser_helper::h(ui_language::translate($buttonaction)),
										   'buttoncss'		 => $buttoncss,
										   'authorurl'       => $authorurl,
										   'version'         => $version,
										   'authoremail'     => $authoremail,
										   'authorname'      => $authorname,
										   'installed'		 => $installed,
										   'title'           => repo_browser_helper::h($title),
										   'installedhtml'   => $installedhtml,
										   'url'			 => $url));
						}
					}
				}
			}
			fclose($file_handle);
			asort($res);
			return $res;
		} else {
			return false;
		}
    }
	
    /**
     * The number of lines in the package list (template <% if TotalPackages %> / <@ totalpackages @>).
     *
     * @global db_driver $zdbh The panel's database connection (unused).
     * @global runtime_controller $controller The request controller (unused).
     * @return int|false The count, or false when there is no package list.
     */
    static function GetTotalPackages() {
        global $zdbh;
		global $controller;
		$modulelist = ctrl_options::GetSystemOption('sentora_root') . "etc/zppy-cache/package.list";
		if (file_exists($modulelist)){
			$lines = file($modulelist);
			$totalpackages = count($lines);
			return $totalpackages;
		} else {
			return false;
		}
	}

	/**
	 * Queue a zppy command typed by the admin.
	 *
	 * @param string $commandtorun The command line without "zppy".
	 * @return bool True when queued (sets self::$zppycommandblank when empty).
	 */
	static function RunZPPYCommand($commandtorun){
		if (!fs_director::CheckForEmptyValue($commandtorun)) {	 
			if (self::RunZPPYClient($commandtorun, false, true)){;
		 		return true;
			} else {
				return false;
			}
		 } else {
		 	self::$zppycommandblank = true;
		 	return false;
		 }
	}
	
	/**
	 * Queue "repo add" for a repository, followed by an update.
	 *
	 * @param string $repotoadd The repository address.
	 * @param mixed $debug Optional. Truthy: show the queued command. Default false.
	 * @return bool True when queued.
	 */
	static function AddRepo($repotoadd, $debug=false){
		 if (!fs_director::CheckForEmptyValue($repotoadd)) {
		 	$command = "repo add " . $repotoadd;
			if (self::RunZPPYClient($command, true, $debug)){;
		 		return true;
			} else {
				return false;
			}
		 } else {
		 	return false;
		 }
	}

	/**
	 * Queue "repo remove" for a repository, followed by an update.
	 *
	 * @param string $repotodelete The repository address.
	 * @param mixed $debug Optional. Non-empty: show the queued command. Default false.
	 * @return bool True when queued.
	 */
	static function ConfirmDeleteRepo($repotodelete, $debug=false){
		if (!fs_director::CheckForEmptyValue($repotodelete)) {
			if (!fs_director::CheckForEmptyValue($debug)) {
				$debug = true;
			}
			$command = "repo remove " . $repotodelete;
			if (self::RunZPPYClient($command, true, $debug)){;
		 		return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	/**
	 * Queue the install of a module ("repo:::module") from a known repository, after checking its .zpp exists.
	 *
	 * Sets self::$modulenotfound when the package is not in the repository.
	 *
	 * @global db_driver $zdbh The panel's database connection (unused).
	 * @global runtime_controller $controller The request controller (unused).
	 * @param string $moduletoadd The "repo:::module" value.
	 * @param mixed $debug Optional. Truthy: show the queued command. Default false.
	 * @return bool True when queued.
	 */
	static function ConfirmInstallModule($moduletoadd, $debug=false){
		global $zdbh;
		global $controller;
		 $split = repo_browser_helper::SplitFull($moduletoadd);
		 if ($split && repo_browser_helper::KnownRepo($split[0]) && !repo_browser_helper::IsCoreModule($split[1])) {
		    $checkurl = repo_browser_helper::Base($split[0]) . "/" . $split[1] . ".zpp";
		 	$moduletoadd = $split;
		 	if (self::is_url_exist($checkurl)){
			 	$command = "install " . $moduletoadd[1];
				if (self::RunZPPYClient($command, true, $debug)){;
					// The root job gives new modules to administrators once installed
			 		return true;
				} else {
					return false;
				}
			} else {
				self::$modulenotfound = true;
				return false;
			}
		 } else {
		 	return false;
		 }
	}

	/**
	 * Queue the removal of a module ("repo:::module"); core modules are refused.
	 *
	 * @param string $moduletodelete The "repo:::module" value.
	 * @param mixed $debug Optional. Truthy: show the queued command. Default false.
	 * @return bool True when queued.
	 */
	static function ConfirmDeleteModule($moduletodelete, $debug=false){
		$moduletodelete = repo_browser_helper::SplitFull($moduletodelete);
		if ($moduletodelete && !repo_browser_helper::IsCoreModule($moduletodelete[1])) {
			$command = "remove " . $moduletodelete[1] . " -y";
			if (self::RunZPPYClient($command, true, $debug)){;
		 		return true;
			} else {
				return false;
			}
			/**
			ob_start();
			system($command, $retval);
			ob_end_clean();
			ob_start();
			system($zppyupdate, $retval);
			ob_end_clean();
			**/
		} else {
			return false;
		}
	}

	/**
	 * Set an installed module's version number in x_modules (Fix version number).
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @param string $module The module folder name.
	 * @param string $version The version from the module's own module.xml.
	 * @return bool True when saved.
	 */
	static function ConfirmUpdateVersionDB($module, $version){
		global $zdbh;
		if (!repo_browser_helper::ValidModule($module) || !repo_browser_helper::ValidVersion((string) $version)) {
			return false;
		}
		try {
			$sql = $zdbh->prepare("UPDATE x_modules SET mo_version_in = :version, mo_updatever_vc = NULL WHERE mo_folder_vc = :folder");
			$sql->bindParam(':version', $version);
			$sql->bindParam(':folder', $module);
			$sql->execute();
			return true;
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * The x_modules row of an installed module.
	 *
	 * @global db_driver $zdbh The panel's database connection.
	 * @param string $module The module folder name.
	 * @return array<int|string, mixed>|false The row, or false when the module is not installed.
	 */
	static function InstalledModule($module){
		global $zdbh;
		$sql = $zdbh->prepare("SELECT * FROM x_modules WHERE mo_folder_vc = :folder");
		$sql->bindParam(':folder', $module);
		$sql->execute();
		return $sql->fetch();
	}

	/**
	 * Queue the upgrade of a module ("repo:::module") from a known repository; core modules are refused.
	 *
	 * @param string $moduletoupdate The "repo:::module" value.
	 * @param mixed $debug Optional. Truthy: show the queued command. Default false.
	 * @return bool True when queued.
	 */
	static function ConfirmUpdateModule($moduletoupdate, $debug=false){
		$moduletoupdate = repo_browser_helper::SplitFull($moduletoupdate);
		if ($moduletoupdate && repo_browser_helper::KnownRepo($moduletoupdate[0]) && !repo_browser_helper::IsCoreModule($moduletoupdate[1])) {
			$command = "upgrade " . $moduletoupdate[1];
			if (self::RunZPPYClient($command, true, $debug)){;
		 		return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}
	
    /**
     * The repositories (template <% loop RepoList %>).
     *
     * @return array<int, array<string, string>>|false See ListRepositories().
     */
    static function getRepoList() {
        $repolist = self::ListRepositories();
        if (!fs_director::CheckForEmptyValue($repolist)) {
            return $repolist;
        } else {
            return false;
        }
    }
	
    /**
     * The packages (template <% loop ModuleList %>).
     *
     * @return array<int, array<string, mixed>>|false See ListModules().
     */
    static function getModuleList() {
        $modulelist = self::ListModules();
        if (!fs_director::CheckForEmptyValue($modulelist)) {
            return $modulelist;
        } else {
            return false;
        }
    }	

    /**
     * Handle "add repository": queue it when it has a packages.txt, otherwise redirect to a confirmation page and exit.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when the address is not valid or could not be queued.
     */
    static function doAddRepo() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
		$formvars['inAddRepo'] = repo_browser_helper::Normalize($formvars['inAddRepo']);
		if (!repo_browser_helper::ValidRepo($formvars['inAddRepo'])) {
			self::$zppycommanderror = true;
			return false;
		}
		if (self::is_url_exist(repo_browser_helper::Base($formvars['inAddRepo']) . "/packages.txt")){
			$debug = NULL;
			if (isset($formvars['inDebugZPPY'])){
				$debug = $formvars['inDebugZPPY'];
			}	
      		if (self::AddRepo($formvars['inAddRepo'], $debug)) {
				self::$ok = true;
	        } else {
	            return false;
	        }
		
		} else {
        	header("location: ./?module=" . $controller->GetCurrentModule() . "&show=ConfirmRepoInstall&other=" . urlencode($formvars['inAddRepo']));
            exit;
		}
        return;
    }

    /**
     * Handle the repository buttons: Remove (redirects to a confirmation page and exits), Retry and dismiss a failed add.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doDeleteRepo() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
            if (isset($formvars['inDeleteRepo'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . urlencode($formvars['inDeleteRepo']));
                exit;
            }
            // Retry: a repository that could not be added is added again; one that is in
            // the list but could not be reached has its package list fetched again
            if (isset($formvars['inRetryRepo']) && repo_browser_helper::ValidRepo($formvars['inRetryRepo'])) {
                $repo = (string) $formvars['inRetryRepo'];
                $command = in_array($repo, repo_browser_helper::Repos(), true) ? 'update' : 'repo add ' . $repo;
                if (self::RunZPPYClient($command, $command !== 'update', false)) {
                    self::$ok = true;
                }
                return;
            }
            // Remove a repository the daemon could not add: forget the failed job
            if (isset($formvars['inDismissRepo'])) {
                $jobs = repo_browser_helper::RepoJobs();
                $repo = (string) $formvars['inDismissRepo'];
                if (isset($jobs[$repo]) && $jobs[$repo]['state'] === 'failed') {
                    global $zdbh;
                    $sql = $zdbh->prepare("UPDATE x_zppy_queue SET zq_status_vc = 'dismissed' WHERE zq_id_pk = :id AND zq_status_vc = 'failed'");
                    $sql->execute(array(':id' => $jobs[$repo]['id']));
                    self::$repodismissed = true;
                }
                return;
            }
        return;
    }

    /**
     * Handle the remove-repository confirmation: queue the removal.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when it could not be queued.
     */
    static function doConfirmDeleteRepo() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
		$debug = NULL;
		if (isset($formvars['inDebugZPPY'])){
			$debug = $formvars['inDebugZPPY'];
		}
        if (self::ConfirmDeleteRepo($formvars['inDelete'], $debug)) {
			self::$ok = true;
        } else {
            return false;
        }
        return;
    }

    /**
     * Whether to show the remove-repository confirmation (template <% if isConfirmDeleteRepo %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisConfirmDeleteRepo() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "Delete"))
            return true;
        return false;
    }

    /**
     * The repository in the URL ('other'), escaped (template <@ CurrentRepo @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getCurrentRepo() {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            return repo_browser_helper::h($controller->GetControllerRequest('URL', 'other'));
        } else {
            return "";
        }
    }

    /**
     * Whether to show the add-repository confirmation (template <% if isConfirmAddRepo %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisConfirmAddRepo() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "ConfirmRepoInstall"))
            return true;
        return false;
    }

    /**
     * Handle the add-repository confirmation: queue it even though no packages.txt was found.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when it could not be queued.
     */
    static function doConfirmAddRepo() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
		$debug = NULL;
		if (isset($formvars['inDebugZPPY'])){
			$debug = $formvars['inDebugZPPY'];
		}
        if (self::AddRepo(repo_browser_helper::Normalize($formvars['inConfirmAddRepo'] ?? ''), $debug)) {
			self::$ok = true;
        } else {
            return false;
        }
        return;
    }

    /**
     * Handle the Install button: queue the install of the posted module (inInstallModule).
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when it could not be queued.
     */
    static function doAddModule() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
		$debug = NULL;
		if (isset($formvars['inDebugZPPY'])){
			$debug = $formvars['inDebugZPPY'];
		}
        if (self::ConfirmInstallModule($formvars['inInstallModule'], $debug)) {
			self::$ok = true;
        } else {
            return false;
        }
        return;
    }
	
    /**
     * "Check all repositories now": queue "zppy update" for the daemon (unless one is waiting
     * already), so the package list is read again within 5 minutes. What the repositories offer
     * right now is shown on the page at once (ajax.php?scan, one repository at a time).
     *
     * Checks the CSRF token.
     *
     * @return void
     */
    static function doCheckAll() {
        runtime_csfr::Protect();
        if (!repo_browser_helper::UpdatePending() && !repo_browser_helper::Enqueue(array('update'))) {
            self::$zppycommanderror = true;
            return;
        }
        self::$checkqueued = true;
    }

    /**
     * The "Rate it" box of an installed Sentora-2027 add-on: 1 to 5 stars and a short comment, sent
     * by the daemon to the feedback server. Only when there is a feedback server; its fields belong
     * to the form rb-rate (outside the module list's own form).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $module
     * @param string $repo
     * @return string HTML ('' when it can not be rated).
     */
    static function RateBox($module, $repo) {
        global $zdbh;
        if (!class_exists('ctrl_feedback') || rtrim($repo, '/') !== ctrl_feedback::ADDON_REPO || ctrl_feedback::Base() === '' || !repo_browser_helper::ValidModule($module)) {
            return '';
        }
        ctrl_feedback::EnsureRatings();
        $q = $zdbh->prepare("SELECT rr_stars_in, rr_comment_tx, rr_sent_in, rr_at_in FROM x_repo_ratings WHERE rr_module_vc = :m");
        $q->execute(array(':m' => $module));
        $mine = $q->fetch(PDO::FETCH_ASSOC);
        $h = function ($s) { return repo_browser_helper::h($s); };
        $t = function ($s) { return repo_browser_helper::h(ui_language::translate($s)); };
        $stars = '';
        for ($i = 5; $i >= 1; $i--) {
            $stars .= '<input type="radio" form="rb-rate" id="rb-s-' . $h($module) . '-' . $i . '" name="inStars_' . $h($module) . '" value="' . $i . '"' . ($mine && (int) $mine['rr_stars_in'] === $i ? ' checked' : '') . '>'
                . '<label for="rb-s-' . $h($module) . '-' . $i . '" title="' . $i . '">&#9733;</label>';
        }
        $state = $mine ? ' (' . str_replace('%s', (string) (int) $mine['rr_stars_in'], $t('yours: %s')) . ((int) $mine['rr_sent_in'] >= (int) $mine['rr_at_in'] ? '' : ', ' . $t('sent within 5 minutes')) . ')' : '';
        return '<details class="rb-rate"><summary>' . $t('Rate it') . $state . '</summary><div class="rb-rate-box">'
            . '<span class="rb-stars">' . $stars . '</span>'
            . '<textarea class="form-control" form="rb-rate" name="inComment_' . $h($module) . '" rows="2" maxlength="500" placeholder="' . $t('What do you think of it? (optional; other panels\' administrators see it)') . '">' . $h($mine ? (string) $mine['rr_comment_tx'] : '') . '</textarea>'
            . '<div class="rb-rate-foot"><small class="rb-sub">' . $t('Sent to the Sentora-2027 feedback server within 5 minutes, with this panel\'s random ID and the version you run; a new rating replaces this one.') . '</small>'
            . '<button class="btn btn-default btn-sm" type="submit" form="rb-rate" name="inRateModule" value="' . $h($module) . '">' . $t('Send') . '</button></div></div></details>';
    }

    /**
     * Rate an installed Sentora-2027 add-on (the "Rate it" box): kept for the daemon to send.
     *
     * Checks the CSRF token.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doRateModule() {
        global $zdbh;
        runtime_csfr::Protect();
        $module = isset($_POST['inRateModule']) && is_string($_POST['inRateModule']) ? $_POST['inRateModule'] : '';
        if (!repo_browser_helper::ValidModule($module) || !self::InstalledModule($module) || !class_exists('ctrl_feedback') || ctrl_feedback::Base() === '') {
            return;
        }
        $stars = isset($_POST['inStars_' . $module]) && is_string($_POST['inStars_' . $module]) && ctype_digit($_POST['inStars_' . $module]) ? (int) $_POST['inStars_' . $module] : 0;
        if ($stars < 1 || $stars > 5) {
            self::$rated = '';
            return;
        }
        $comment = isset($_POST['inComment_' . $module]) && is_string($_POST['inComment_' . $module]) ? $_POST['inComment_' . $module] : '';
        $comment = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', mb_convert_encoding($comment, 'UTF-8', 'UTF-8')))), 0, 500);
        ctrl_feedback::EnsureRatings();
        $me = ctrl_users::GetUserDetail();
        $zdbh->prepare("REPLACE INTO x_repo_ratings (rr_module_vc, rr_stars_in, rr_comment_tx, rr_acc_fk, rr_at_in, rr_sent_in) VALUES (:m, :s, :c, :a, :t, NULL)")
            ->execute(array(':m' => $module, ':s' => $stars, ':c' => $comment, ':a' => (int) $me['userid'], ':t' => time()));
        self::$rated = $module;
    }

    /**
     * Is a "zppy update" waiting for the daemon (template <% if UpdatePending %>)?
     *
     * @return bool
     */
    static function getUpdatePending() {
        return repo_browser_helper::UpdatePending();
    }

    /**
     * Handle the zppy command box: queue the posted command.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when it could not be queued.
     */
    static function doRunZPPYCommand() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::RunZPPYCommand($formvars['inRunZPPYCommand'])) {
			self::$ok = true;
        } else {
            return false;
        }
        return;
    }


    /**
     * Handle the package buttons (remove, install, fix version, update): redirect to the matching confirmation page and exit.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doDeleteModule() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
		$urlvars = $controller->GetAllControllerRequests('URL');
            if (isset($formvars['inRemoveModule'])) {
	                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=ConfirmModuleDelete&other=" . urlencode($formvars['inRemoveModule']));
	                exit;
            }
            if (isset($formvars['inInstallModule'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=ConfirmModuleInstall&other=" . urlencode($formvars['inInstallModule']));
                exit;
            }
            if (isset($formvars['inUpdateVersionDB'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=ConfirmUpdateVersionDB&other=" . urlencode($formvars['inUpdateVersionDB']));
                exit;
            }
            if (isset($formvars['inUpdateModule'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=ConfirmModuleUpdate&other=" . urlencode($formvars['inUpdateModule']));
                exit;
            }
        return;
    }

    /**
     * Handle the remove-module confirmation: queue the removal.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when it could not be queued.
     */
    static function doConfirmDeleteModule() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
		$debug = NULL;
		if (isset($formvars['inDebugZPPY'])){
			$debug = $formvars['inDebugZPPY'];
		}
        if (self::ConfirmDeleteModule($formvars['inDelete'], $debug)) {
			self::$ok = true;
        } else {
            return false;
        }
        return;
    }

    /**
     * Handle the install-module confirmation: queue the install.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when it could not be queued.
     */
    static function doConfirmInstallModule() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
		$debug = NULL;
		if (isset($formvars['inDebugZPPY'])){
			$debug = $formvars['inDebugZPPY'];
		}
        if (self::ConfirmInstallModule($formvars['inInstall'], $debug)) {
			self::$ok = true;
        } else {
            return false;
        }
        return;
    }

    /**
     * Handle the fix-version confirmation: save the module's version number.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when it could not be saved.
     */
    static function doConfirmUpdateVersionDB() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ConfirmUpdateVersionDB($formvars['inUpdate'], $formvars['inVersion'])) {
			self::$ok = true;
        } else {
            return false;
        }
        return;
    }

    /**
     * Handle the update-module confirmation: queue the upgrade.
     *
     * Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return false|null False when it could not be queued.
     */
    static function doConfirmUpdateModule() {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
		$debug = NULL;
		if (isset($formvars['inDebugZPPY'])){
			$debug = $formvars['inDebugZPPY'];
		}
        if (self::ConfirmUpdateModule($formvars['inUpdate'], $debug)) {
			self::$ok = true;
        } else {
            return false;
        }
        return;
    }

    /**
     * "Update all" (the Updates tab): queue the upgrade of every module the page found an update for.
     *
     * The page sends the modules as "repo:::module" separated by commas; each one is checked as a
     * single update is (a known repository, not a core module, installed, not already queued), then
     * one list update follows. Checks the CSRF token.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doUpdateAll() {
        global $controller;
        runtime_csfr::Protect();
        $n = 0;
        $skipped = 0;
        $seen = array();
        foreach (array_slice(explode(',', (string) $controller->GetControllerRequest('FORM', 'inUpdateAll')), 0, 200) as $full) {
            $m = repo_browser_helper::SplitFull(trim($full));
            if (!$m || isset($seen[$m[1]])) {
                continue;
            }
            $seen[$m[1]] = true;
            if (!repo_browser_helper::KnownRepo($m[0]) || repo_browser_helper::IsCoreModule($m[1]) || !self::IsInstalled($m[1])) {
                $skipped++;
                continue;
            }
            if (self::RunZPPYClient('upgrade ' . $m[1], false, false)) {
                $n++;
            } else {
                $skipped++;
            }
        }
        // (RunZPPYClient's flags would show a single module's message: this page says it for all)
        self::$alreadyqueued = null;
        self::$zppycommanderror = null;
        if ($n > 0) {
            repo_browser_helper::Enqueue(array('update'));
        }
        self::$updateall = array($n, $skipped);
    }

    /**
     * Is a module (folder name) installed on this panel?
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $folder The module's folder name.
     * @return bool
     */
    private static function IsInstalled($folder) {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc = :f");
        $q->execute(array(':f' => (string) $folder));
        return (int) $q->fetchColumn() > 0;
    }

    /**
     * Whether to show the remove-module confirmation (template <% if isConfirmDeleteModule %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisConfirmDeleteModule() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "ConfirmModuleDelete"))
            return true;
        return false;
    }

    /**
     * Whether to show the ZPPY patch page (no longer used).
     *
     * @return false
     */
    static function getisConfirmZPPYPatch() {
        return false;
    }

    /**
     * Whether to show the install-module confirmation (template <% if isConfirmInstallModule %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisConfirmInstallModule() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "ConfirmModuleInstall"))
            return true;
        return false;
    }

    /**
     * Whether to show the fix-version confirmation (template <% if isConfirmUpdateVersionDB %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisConfirmUpdateVersionDB() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "ConfirmUpdateVersionDB"))
            return true;
        return false;
    }

    /**
     * Whether to show the update-module confirmation (template <% if isConfirmUpdateModule %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisConfirmUpdateModule() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "ConfirmModuleUpdate"))
            return true;
        return false;
    }

    /**
     * The whole 'other' URL value, escaped (template <@ CurrentModuleFull @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getCurrentModuleFull() {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            return repo_browser_helper::h($controller->GetControllerRequest('URL', 'other'));
        } else {
            return "";
        }
    }

    /**
     * The second ":::" part of 'other' (the module name), escaped (template <@ CurrentModuleName @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getCurrentModuleName() {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
			$Modulename = explode(":::", $controller->GetControllerRequest('URL', 'other'));
			if (isset($Modulename[1])){
				return repo_browser_helper::h($Modulename[1]);
			} else {
				return "";
			}
        } else {
            return "";
        }
    }

    /**
     * The first ":::" part of 'other' (the version, for "fix version number"), escaped (template <@ CurrentModuleVersion @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getCurrentModuleVersion() {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
			$Moduleversion = explode(":::", $controller->GetControllerRequest('URL', 'other'));
			if (isset($Moduleversion[0])){
				return repo_browser_helper::h($Moduleversion[0]);
			} else {
				return "";
			}
        } else {
            return "";
        }
    }

    /**
     * The third ":::" part of 'other' (the installed version), escaped (template <@ CurrentModuleInstalledVersion @>).
     *
     * @global runtime_controller $controller The request controller.
     * @return string
     */
    static function getCurrentModuleInstalledVersion() {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
			$Moduleversion = explode(":::", $controller->GetControllerRequest('URL', 'other'));
			if (isset($Moduleversion[2])){
				return repo_browser_helper::h($Moduleversion[2]);
			} else {
				return "";
			}
        } else {
            return "";
        }
    }

    /**
     * Whether to show the main page: no 'show' in the URL (template <% if isMain %>).
     *
     * @global runtime_controller $controller The request controller.
     * @return bool
     */
    static function getisMain() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((!isset($urlvars['show'])))
            return true;
        return false;
    }

	/**
	 * The logged-in account's ID (template <@ ZPanelID @>).
	 *
	 * @return int|string|false
	 */
	static function getZPanelID() {
		return ctrl_auth::CurrentUserID();
    }






	/**
	 * Whether a URL answers 200 (see repo_browser_helper::UrlExists()).
	 *
	 * @param string $url The URL.
	 * @return bool
	 */
	static function is_url_exist($url){
		return repo_browser_helper::UrlExists($url);
	}

	/**
	 * Queue a zppy command for the daemon (root), optionally followed by an update.
	 *
	 * Refuses empty commands, commands starting with "zppy", install-upload, invalid
	 * arguments and a second job for the same module or repository (sets the matching flag).
	 *
	 * @param string $command The command line without "zppy".
	 * @param bool $update Optional. Also queue "update" afterwards. Default false.
	 * @param mixed $out Optional. Truthy: show the queued command (self::$zppyout1). Default false.
	 * @return bool True when queued.
	 */
	static function RunZPPYClient($command, $update=false, $out=false){
		$args = preg_split('/\s+/', trim((string) $command));
		if (fs_director::CheckForEmptyValue($command) || count($args) == 0 || $args[0] === '') {
			self::$zppycommandblank = true;
			return false;
		}
		if (strtolower($args[0]) == 'zppy'){
			self::$zppycommandzppynotneeded = true;
			return false;
		}
		$repoJobs = ($args[0] === 'repo' && isset($args[2])) ? repo_browser_helper::RepoJobs() : array();
		if ((isset($args[1]) && in_array($args[0], array('install', 'remove', 'upgrade'), true) && repo_browser_helper::Pending($args[1]) !== false)
			|| (isset($args[2], $repoJobs[$args[2]]) && $repoJobs[$args[2]]['state'] === 'queued')) {
			self::$alreadyqueued = true; // one job at a time per module
			return false;
		}
		if ($args[0] === 'install-upload' || !repo_browser_helper::Enqueue($args)){
			self::$zppycommanderror = true;
			return false;
		}
		if ($update == true && $args[0] !== 'update'){
			repo_browser_helper::Enqueue(array('update'));
		}
		if ($out == true){
			self::$zppyout1 = "<strong>" . ui_language::translate("Queued:") . "</strong> zppy " . repo_browser_helper::h(implode(' ', $args))
				. "<br>" . ui_language::translate("The daemon (root) makes the change (this can take up to 5 minutes); the result appears under Recent ZPPY commands.") . "<br>";
		}
		return true;
	}

	/**
	 * Check zppy arguments (see repo_browser_helper::ValidZPPYArguments()).
	 *
	 * @param string[] $args The arguments.
	 * @return bool
	 */
	static function ValidZPPYArguments($args){
		return repo_browser_helper::ValidZPPYArguments($args);
	}

	/**
	 * The table rows of the last 10 queued zppy commands (template <@ QueueRows @>).
	 *
	 * @return string The HTML <tr> rows.
	 */
	static function getQueueRows(){
		$rows = '';
		foreach (repo_browser_helper::Recent(10) as $r) {
			$args = json_decode((string) $r['zq_args_tx'], true);
			$st = (string) $r['zq_status_vc'];
			$cls = $st === 'done' || $st === 'ok' ? 'label-success' : ($st === 'failed' || $st === 'error' ? 'label-danger' : 'label-warning');
			$output = trim((string) $r['zq_output_tx']);
			$rows .= '<tr><td class="rb-when">' . date('Y-m-d H:i', (int) $r['zq_created_in']) . '</td><td><code>zppy ' . repo_browser_helper::h(implode(' ', (array) $args)) . '</code></td><td><span class="label ' . $cls . '">'
				. repo_browser_helper::h(ui_language::translate($st)) . '</span></td><td>' . ($output !== '' ? '<details><summary>' . ui_language::translate('Output') . '</summary><pre class="rb-out">' . repo_browser_helper::h($output) . '</pre></details>' : '<span class="rb-sub">–</span>') . '</td></tr>';
		}
		return $rows === '' ? '<tr><td colspan="4" class="rb-empty">' . ui_language::translate('No commands yet.') . '</td></tr>' : $rows;
	}


    /**
     * The question before removing a repository or module (template <@ RemoveConfirm @>), translated and escaped for an attribute.
     *
     * @return string
     */
    static function getRemoveConfirm() {
        return repo_browser_helper::h(ui_language::translate('Remove it?'));
    }

    /**
     * The repository address formats (template <@ RepoFormatHint @>), translated and escaped.
     *
     * @return string
     */
    static function getRepoFormatHint() {
        return strtr(repo_browser_helper::h(ui_language::translate('Where modules come from. A repository is written as %1$s, or %2$s for plain http on your own network.')),
            array('%1$s' => 'https://host/path', '%2$s' => repo_browser_helper::h('host[:port][/path]')));
    }

    /**
     * The developer note on download counts (template <@ RepoStatsHint @>), translated and escaped.
     *
     * @return string
     */
    static function getRepoStatsHint() {
        return strtr(repo_browser_helper::h(ui_language::translate('Download counts show when the repository also serves %1$s (lines of: module count) or %2$s.')),
            array('%1$s' => 'repostatslist', '%2$s' => repo_browser_helper::h('repostats.php?json=true')));
    }

    /**
     * The message box for the last action (template <@ result @>).
     *
     * @return string|null The HTML, or null when there is nothing to show.
     */
    static function getResult() {
        if (self::$updateall !== null) {
            list($n, $skipped) = self::$updateall;
            if ($n === 0) {
                return ui_sysmessage::shout(ui_language::translate("No update was queued: each of those modules already has a change waiting, or is no longer offered by its repository."), "zannounceerror");
            }
            $msg = str_replace('%s', (string) $n, ui_language::translate("Queued %s module update(s): the daemon installs them one after another (this can take a few minutes). The results appear under Recent ZPPY commands."));
            if ($skipped > 0) {
                $msg .= ' ' . str_replace('%s', (string) $skipped, ui_language::translate("%s were left out (already waiting, or not from a repository you have)."));
            }
            return ui_sysmessage::shout($msg, "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyqueued)) {
            return ui_sysmessage::shout(ui_language::translate("That module or repository already has a change queued (this can take up to 5 minutes); you can queue another once it has finished (see Recent ZPPY commands)."), "zannounceerror");
        }
        if (self::$rated !== null) {
            return self::$rated === ''
                ? ui_sysmessage::shout(ui_language::translate("Choose 1 to 5 stars, then Send."), "zannounceerror")
                : ui_sysmessage::shout(str_replace('%s', self::$rated, ui_language::translate("Thank you: your rating of %s is sent to the feedback server within 5 minutes.")), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$checkqueued)) {
            return ui_sysmessage::shout(ui_language::translate("Checking every repository now: what each one offers appears beside it below. The daemon reads their lists again within 5 minutes, so that new modules can then be installed."), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$repodismissed)) {
            return ui_sysmessage::shout(ui_language::translate("The repository that could not be added has been removed from the list."), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Queued: the daemon makes the change (this can take up to 5 minutes). The result appears under Recent ZPPY commands."), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$modulenotfound)) {
            return ui_sysmessage::shout(ui_language::translate("ZPPY can not install this module because it does not exist in the repository."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$zppycommandzppynotneeded)) {
            return ui_sysmessage::shout(ui_language::translate("You do not need to start the command with \"zppy\"."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$zppycommanderror)) {
            return ui_sysmessage::shout(ui_language::translate("You have entered an invalid ZPPY command."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$zppycommandblank)) {
			return ui_sysmessage::shout(ui_language::translate("You have entered an invalid ZPPY command."), "zannounceerror");
		}
		if (!fs_director::CheckForEmptyValue(self::$zppypatchok)) {
			return ui_sysmessage::shout(ui_language::translate("Repo Browser has successfully installed the ZPPY patch file. You can now remove your module with Repo Browser"), "zannounceok");
		}
		if (!fs_director::CheckForEmptyValue(self::$zppypatcherror)) {
			return ui_sysmessage::shout(ui_language::translate("There was a problem patching your ZPPY file. If you still cannot delete a module, then try installing the patch manually. (See developer notes)"), "zannounceerror");
		}
        return;
    }

    /**
     * The box with the queued command (template <@ ZppyOut @>).
     *
     * @return string|null The HTML, or null when there is nothing to show.
     */
    static function getZppyOut() {
		$out = false;
		if (!fs_director::CheckForEmptyValue(self::$zppyout1)) {
			$out = self::$zppyout1;
		}
		if (!fs_director::CheckForEmptyValue(self::$zppyout2)) {
			$out = self::$zppyout2;
		}
		if (!fs_director::CheckForEmptyValue(self::$zppyout1) && !fs_director::CheckForEmptyValue(self::$zppyout2)) {
			$out = self::$zppyout1 . "<br>" . self::$zppyout2;
		}
        if (!fs_director::CheckForEmptyValue($out)) {
            return "<div class=\"rb-debug\"><strong>" . ui_language::translate("ZPPY command output") . "</strong><div>" . $out . "</div></div>";
        }
        return;
    }

	/**
	 * Whether a module ships with the panel (see repo_browser_helper::IsCoreModule()).
	 *
	 * @param string $module The module folder name.
	 * @return bool
	 */
	static function ForbiddenModule($module){
		return repo_browser_helper::IsCoreModule($module);
	}

}

?>