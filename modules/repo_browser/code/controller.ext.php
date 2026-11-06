<?php

require_once dirname(__FILE__) . '/repohelper.php';

/**
 *
 * Sentora - repo browser zpanel plugin, written by RusTus: www.zpanelcp.com.
 *
 */
 
class module_controller {

    /** the warning icon in the page (ui_status, no image file) */
    static function getWarnIcon()
    {
        return ui_status::Warn('Warning');
    }


    static $ok = NULL;
	static $zppyout1 = NULL;
	static $zppyout2 = NULL;
	static $modulenotfound = NULL;
	static $zppycommanderror = NULL;
	static $alreadyqueued = NULL;
	static $repodismissed = NULL;
	static $zppycommandblank = NULL;
	static $zppycommandzppynotneeded = NULL;
	static $zppypatchok = NULL;
	static $zppypatcherror = NULL;

    /**
     * The repositories: those in the list (checked live by ajax.php; a Retry button
     * appears if the check fails), those waiting to be added or removed (Queued), and
     * those the daemon could not add (Failed, with Retry and Remove)
     */
    static function ListRepositories() {
		$h = function ($s) { return repo_browser_helper::h($s); };
		$label = function ($class, $text, $title) use ($h) {
			return '<a href="javascript:void(0)" title="' . $h($title) . '"><span class="label ' . $class . '">' . $h($text) . '</span></a>';
		};
		$queuedButton = '<button class="btn btn-default disabled" type="button" disabled="disabled" title="'
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
				$status = $label('label-warning', 'QUEUED', ui_language::translate('Removal queued (this can take up to 5 minutes).'));
				$buttons = $queuedButton;
			} else {
				$status = '<div class="controls" item_id="R:::' . $h($repo) . '"><img src="modules/repo_browser/assets/ajax-loader.gif" alt="loading..." /></div>';
				$buttons = '<button class="button-loader btn btn-primary retry-repo" type="submit" name="inRetryRepo" value="' . $h($repo) . '" style="display:none">' . ui_language::translate('Retry') . '</button> '
					. '<button class="button-loader delete btn btn-danger" type="submit" name="inDeleteRepo" value="' . $h($repo) . '">' . ui_language::translate('Remove') . '</button>';
			}
			$res[$repo] = array('line' => $h($repo), 'statushtml' => $status, 'buttonshtml' => $buttons);
		}
		foreach ($jobs as $repo => $job) {
			if (isset($listed[$repo]) || $job['action'] !== 'add' || !repo_browser_helper::ValidRepo($repo)) {
				continue;
			}
			if ($job['state'] === 'queued') {
				$status = $label('label-warning', 'QUEUED', ui_language::translate('Waiting to be added (this can take up to 5 minutes).'));
				$buttons = $queuedButton;
			} else {
				$status = $label('label-danger', 'FAILED', ui_language::translate('The daemon could not add this repository; see Recent ZPPY commands.'));
				$buttons = '<button class="button-loader btn btn-primary" type="submit" name="inRetryRepo" value="' . $h($repo) . '">' . ui_language::translate('Retry') . '</button> '
					. '<button class="button-loader delete btn btn-danger" type="submit" name="inDismissRepo" value="' . $h($repo) . '">' . ui_language::translate('Remove') . '</button>';
			}
			$res[$repo] = array('line' => $h($repo), 'statushtml' => $status, 'buttonshtml' => $buttons);
		}
		if (!count($res)) {
			return false;
		}
		ksort($res);
		return array_values($res);
    }
	
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
								$title = $content[0] . " is already installed on this server.";
								$info = ui_module::GetModuleXMLTags($modinfo['mo_folder_vc']);
								$description = repo_browser_helper::h($modinfo['mo_desc_tx']);
								$authorurl = $info['authorurl'];
								$version = $info['version'];
								$authoremail = $info['authoremail'];
								$authorname = $info['authorname'];
								$buttonaction = "Remove";
								$buttoncss = "button-loader delete btn btn-danger fg-button ui-state-default ui-corner-all";
								$installedhtml = "<a href=\"./?module=" . $content[0] . "\" title=\"Launch module\">" . $content[0] . "</a>";						
							} else {
								$installed = "notinstalled";
								$title = "To install this module, click: INSTALL";
								$description = "No description available.";
								$authorurl = "";
								$version = "not available";
								$authoremail = "not available";
								$authorname = "not available";
								$buttonaction = "Install";
								$buttoncss = "button-loader btn btn-primary fg-button ui-state-default ui-corner-all";
								$installedhtml = $content[0];
							} 
							// A job for this module is waiting for the daemon: no second one
							$buttonattr = '';
							$pending = repo_browser_helper::Pending($content[0]);
							if ($pending !== false) {
								$buttonaction = "Queued";
								$buttoncss = "btn btn-default disabled";
								$buttonattr = 'disabled="disabled"';
								$title = ($pending === 'remove' ? 'Removal' : ($pending === 'upgrade' ? 'Upgrade' : 'Installation')) . " queued: the daemon runs it within 5 minutes.";
							}
	                		array_push($res, array('modulename'  => trim($content[0]),
										   'buttonattr'      => $buttonattr,
                    				   	   'reponame'        => trim($content[1]),
										   'description'     => $description,
										   'buttonaction'    => $buttonaction,
										   'buttoncss'		 => $buttoncss,
										   'authorurl'       => $authorurl,
										   'version'         => $version,
										   'authoremail'     => $authoremail,
										   'authorname'      => $authorname,
										   'installed'		 => $installed,
										   'title'           => $title,
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

	static function InstalledModule($module){
		global $zdbh;
		$sql = $zdbh->prepare("SELECT * FROM x_modules WHERE mo_folder_vc = :folder");
		$sql->bindParam(':folder', $module);
		$sql->execute();
		return $sql->fetch();
	}

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
	
    static function getRepoList() {
        $repolist = self::ListRepositories();
        if (!fs_director::CheckForEmptyValue($repolist)) {
            return $repolist;
        } else {
            return false;
        }
    }
	
    static function getModuleList() {
        $modulelist = self::ListModules();
        if (!fs_director::CheckForEmptyValue($modulelist)) {
            return $modulelist;
        } else {
            return false;
        }
    }	

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

    static function getisConfirmDeleteRepo() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "Delete"))
            return true;
        return false;
    }

    static function getCurrentRepo() {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            return repo_browser_helper::h($controller->GetControllerRequest('URL', 'other'));
        } else {
            return "";
        }
    }

    static function getisConfirmAddRepo() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "ConfirmRepoInstall"))
            return true;
        return false;
    }

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

    static function getisConfirmDeleteModule() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "ConfirmModuleDelete"))
            return true;
        return false;
    }

    static function getisConfirmZPPYPatch() {
        return false;
    }

    static function getisConfirmInstallModule() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "ConfirmModuleInstall"))
            return true;
        return false;
    }

    static function getisConfirmUpdateVersionDB() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "ConfirmUpdateVersionDB"))
            return true;
        return false;
    }

    static function getisConfirmUpdateModule() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "ConfirmModuleUpdate"))
            return true;
        return false;
    }

    static function getCurrentModuleFull() {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            return repo_browser_helper::h($controller->GetControllerRequest('URL', 'other'));
        } else {
            return "";
        }
    }

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

    static function getisMain() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((!isset($urlvars['show'])))
            return true;
        return false;
    }

	static function getZPanelID() {
		return ctrl_auth::CurrentUserID();
    }

	static function getModuleDesc() {
			return ui_module::GetModuleDescription();
    }

    static function getModulePath() {
        global $controller;
        $module_path = "modules/" . $controller->GetControllerRequest('URL', 'module') . "/";
        return $module_path;
    }

	static function getModuleName() {
		$module_name = ui_module::GetModuleName();
        return $module_name;
    }

	static function getModuleIcon() {
		global $controller;
		$module_icon = "/modules/" . $controller->GetControllerRequest('URL', 'module') . "/assets/icon.png";
        return $module_icon;
    }

    static function getCSFR_Tag() {
        return runtime_csfr::Token();
    }

	static function is_url_exist($url){
		return repo_browser_helper::UrlExists($url);
	}

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
			self::$zppyout1 = "<strong>" . ui_language::translate("Queued") . ":</strong> zppy " . repo_browser_helper::h(implode(' ', $args))
				. "<br>" . ui_language::translate("The daemon (root) makes the change (this can take up to 5 minutes); the result appears under Recent ZPPY commands.") . "<br>";
		}
		return true;
	}

	static function ValidZPPYArguments($args){
		return repo_browser_helper::ValidZPPYArguments($args);
	}

	static function getQueueRows(){
		$rows = '';
		foreach (repo_browser_helper::Recent(10) as $r) {
			$args = json_decode((string) $r['zq_args_tx'], true);
			$rows .= '<tr><td>' . date('Y-m-d H:i', (int) $r['zq_created_in']) . '</td><td><code>zppy ' . repo_browser_helper::h(implode(' ', (array) $args)) . '</code></td><td>'
				. repo_browser_helper::h($r['zq_status_vc']) . '</td><td><pre style="white-space: pre-wrap; margin: 0">' . repo_browser_helper::h((string) $r['zq_output_tx']) . '</pre></td></tr>';
		}
		return $rows === '' ? '<tr><td colspan="4">' . ui_language::translate('No commands yet.') . '</td></tr>' : $rows;
	}


    static function getResult() {
        if (!fs_director::CheckForEmptyValue(self::$alreadyqueued)) {
            return ui_sysmessage::shout(ui_language::translate("That module or repository already has a change queued (this can take up to 5 minutes); you can queue another once it has finished (see Recent ZPPY commands)."), "zannounceerror");
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
            return ui_sysmessage::shout(ui_language::translate("You do not need to proceed the command with \"zppy\"."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$zppycommanderror)) {
            return ui_sysmessage::shout(ui_language::translate("You have entered and invalid ZPPY command."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$zppycommandblank)) {
			return ui_sysmessage::shout(ui_language::translate("You have entered and invalid ZPPY command."), "zannounceerror");
		}
		if (!fs_director::CheckForEmptyValue(self::$zppypatchok)) {
			return ui_sysmessage::shout(ui_language::translate("Repo Browser has successfully installed the ZPPY patch file. You can now remove your module with Repo Browser"), "zannounceok");
		}
		if (!fs_director::CheckForEmptyValue(self::$zppypatcherror)) {
			return ui_sysmessage::shout(ui_language::translate("There was a problem patching your ZPPY file. If you still cannot delete a module, then try installing the patch manually. (See developer notes)"), "zannounceerror");
		}
        return;
    }

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
            return "<div class=\"zdebug alert alert-block alert-danger\"><button class=\"close\" data-dismiss=\"alert\" type=\"button\">&times</button><strong>" . ui_language::translate("ZPPY COMMAND OUTPUT") . ":</strong><br><br>" . $out . "</div>";
        }
        return;
    }

	static function ForbiddenModule($module){
		return repo_browser_helper::IsCoreModule($module);
	}

}

?>