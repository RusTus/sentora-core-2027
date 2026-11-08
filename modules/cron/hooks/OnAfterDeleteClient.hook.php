<?php

/**
 * Cron Manager: a deleted client's tasks are deleted too. The jobs themselves are
 * written by the daemon (sys_accountphp::SyncCron), which leaves out deleted and
 * disabled accounts.
 *
 * Updated by RusTus, 2026-11-05
 */
global $zdbh; // hooks are included inside a function
$zdbh->prepare("UPDATE x_cronjobs c JOIN x_accounts a ON a.ac_id_pk = c.ct_acc_fk SET c.ct_deleted_ts = :t
    WHERE a.ac_deleted_ts IS NOT NULL AND c.ct_deleted_ts IS NULL")->execute(array(':t' => time()));
