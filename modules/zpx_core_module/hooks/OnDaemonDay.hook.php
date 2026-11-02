<?php

global $zdbh;

/*
 * This private build does not contact sentora.org:
 * - the daily module update check fetched every module's <updateurl> (one slow
 *   timeout per module without internet access) and could offer upstream code
 *   that would replace this build's patched modules;
 * - the daily report sent the panel domain and the zadmin email address to
 *   api.sentora.org.
 * Both are switched off. Clear any update notices left over from earlier runs.
 */
$versionsql = $zdbh->prepare("UPDATE x_modules SET mo_updatever_vc = '', mo_updateurl_tx = ''");
$versionsql->execute();
echo fs_filehandler::NewLine() . "Module update checks and usage reporting are disabled in this build." . fs_filehandler::NewLine();

return true;
?>
