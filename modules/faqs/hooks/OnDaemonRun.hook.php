<?php

/**
 * FAQ: once, on a server installed before Sentora-2027.13. Every built-in group (Administrators,
 * Resellers, Users) gets the FAQ module (no group had it, so nobody saw it), and the standard
 * answers that still read as Sentora 2.0.2 shipped them are replaced with ones that match this panel
 * (an answer the administrator changed is left as it is); three new ones are added (certificates,
 * backups, two-factor login) unless a question with that text is there.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-07
 */
global $zdbh;
if (ctrl_options::GetSystemOption('faqs_2027_done') === false) {
    $fqMod = (int) $zdbh->query("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = 'faqs'")->fetchColumn();
    if ($fqMod > 0) {
        foreach ($zdbh->query("SELECT ug_id_pk FROM x_groups WHERE ug_reseller_fk = 1 AND ug_name_vc IN ('Administrators', 'Resellers', 'Users')")->fetchAll(PDO::FETCH_COLUMN) as $fqGroup) {
            if (!ctrl_groups::CheckGroupModulePermissions((int) $fqGroup, $fqMod)) {
                $zdbh->prepare("INSERT INTO x_permissions (pe_group_fk, pe_module_fk) VALUES (:g, :m)")->execute(array(':g' => (int) $fqGroup, ':m' => $fqMod));
            }
        }
    }
    // array(old question, old answer, new question, new answer); old null: a new one
    $fqList = array(
    array('How can I update my personal contact details?', 'From the control panel homepage please click on the &quot;My Account&quot; icon to enable you to update your personal details.', 'How can I update my personal contact details?', 'Go to Account Information &gt; My Account. There you can also choose the panel&#39;s language and look, and turn on two-factor login.'),
    array('How do I change my password?', 'Your Sentora and MySQL password can be easily changed using the &quot;Change Password&quot; icon on the control panel.', 'How do I change my password?', 'Your panel password: Account Information &gt; Change Password. A MySQL user&#39;s password: Database Management &gt; MySQL Users. A mailbox password: Mail &gt; Mailboxes, or in webmail (Settings &gt; Password). An FTP password: File Management &gt; FTP Accounts.'),
    array('I don&#39;t think one of the services(Apache, MySQL, FTP, etc) are running. Is there any easy way to check?', 'Sentora comes with a service monitoring system that checks to make sure all the services are up and running, Simply go to your Control Panel Home and select the module called &quot;Service Status&quot;. From there you will be able to see if any of the services are down or up.', 'I don&#39;t think one of the services (websites, mail, FTP, databases) is running. Is there an easy way to check?', 'Go to Advanced &gt; Service Status: it shows which services answer, and when the panel last applied your changes. If one is down, tell your host.'),
    array('How can I set my domain to work with my Sentora Account?', 'To setup up a domain with Sentora first thing you need to do is go &quot;Domains&quot; and add your to the list. Next you need to set the Name Server on your Domain Registrar to match that of your host. This information can be obtained by contacting your host.', 'How can I set my domain to work with my account?', 'Add it in Domain Management &gt; Domains (it is ready within 5 minutes). Then, at the company you registered the domain with, set its name servers to the ones your host gives you, or point its address (A record) to this server.'),
    array('How can I create a MySQL Database?', 'To create a MySQL database simply go to the section of the panel called &quot;Database Management&quot; and select the module called &quot;MySQL Databases&quot; from here you will easily be able to add and manage databases on your account.', 'How can I create a MySQL database?', 'Go to Database Management &gt; MySQL Database and add it. Then, in MySQL Users, make a user and give it the database: your website logs in with that user.'),
    array('What is phpMyAdmin?', 'phpMyAdmin is an open source tool intended to handle the administration of MySQL databases. It can perform various tasks such as creating, modifying or deleting databases, tables, fields or rows or executing SQL statements', 'What is phpMyAdmin?', 'phpMyAdmin manages your MySQL databases in the browser: tables, data, imports and exports, and SQL. Open it from Database Management &gt; phpMyAdmin and log in with a user from MySQL Users.'),
    array('How do I create FTP Accounts?', 'You can create FTP accounts by going to &quot;FTP Accounts&quot; from their you can add accounts and manage quotas and directories. ', 'How do I create FTP accounts?', 'Go to File Management &gt; FTP Accounts, add a login and choose the folder it may use. Connect with FTPS (FTP over TLS, required) to this server on port 21; FileZilla calls it &quot;Require explicit FTP over TLS&quot;.'),
    array('How do I create an E-Mail Account?', 'Go to the Mail section of Sentora and select the module called &quot;Mailboxes&quot;, from here you can create E-Mail account for each domain setup on your account. You can also reset passwords to previously created accounts.', 'How do I create an email account?', 'Go to Mail &gt; Mailboxes and add one for any of your domains. Each mailbox has its own password: its user can change it in webmail, and you can set a new one here (it is never shown).'),
    array('How do I create a Mail Alias?', 'Go to the Mail section of Sentora and select the module called &quot;Aliases&quot;, from here you can create Alias E-Mail accounts for each previously created E-Mail account. All mail sent to the alias will be delivered to the master e-mail account.', 'How do I create a mail alias?', 'Go to Mail &gt; Aliases: an alias is another address for an existing mailbox, and all mail sent to it arrives in that mailbox.'),
    array('How can I create a Mailing List?', 'Mailing lists can be setup by going to the Mail section of Sentora and select the module called &quot;Distribution Lists&quot;, from here you can create Mailing lists by creating an E-mail Account. ', 'How can I create a mailing list?', 'Go to Mail &gt; Distribution Lists: make the list&#39;s address and add the addresses that should receive its mail.'),
    array('How do I use Mail Forwards?', 'Go to the Mail section of Sentora and select the module called &quot;Forwards&quot;, from here you can create E-Mail address on your domains that will forward to other E-Mail addresses that are on different servers like &quot;G-Mail, Yahoo, and MSN&quot;. ', 'How do I use mail forwards?', 'Go to Mail &gt; Forwards: mail sent to an address on your domain is passed on to another address, also one on another server.'),
    array('What are Subdomains?', 'A subdomain combines a unique identifier with a domain name to become essentially a &quot;domain within a domain.&quot; The unique identifier simply replaces the www in the web address. Yahoo!, for example, uses subdomains such as mail.yahoo.com and music.yahoo.com to reference its mail and music services, under the umbrella of www.yahoo.com. They can be created by using the Subdomain module in the Domains section. You can assign directories for each sub domain from the module.', 'What are subdomains?', 'A subdomain is a name under your domain, such as shop.example.com, with its own website folder. Add them in Domain Management &gt; Sub Domains.'),
    array('How can I view how much Data I have used?', 'You can view how much data you have used by accessing the &quot;Usage Viewer&quot; under the Account Information section of Sentora. It displays account information in different formats. It displays Data Usage, Domain Usage, Bandwidth Usage, MySQL Usage, and much more.', 'How can I see how much space and bandwidth I have used?', 'Go to Account Information &gt; Usage Viewer: it shows your disk space, bandwidth, domains, mailboxes and databases against your package&#39;s limits.'),
    array('How can I access Webmail?', 'Go to the Mail section of Sentora and select the module called &quot;Webmail&quot;, from here you can login to your E-Mail account and view and create messages. ', 'How can I read my mail in the browser?', 'Go to Mail &gt; WebMail: it opens Roundcube, and SOGo for calendars and contacts. Log in with the mailbox&#39;s address and password.'),
    array(null, null, 'How do I get a certificate (https) for my website?', 'Go to Domain Management &gt; Sencrypt SSL and request a free Let&#39;s Encrypt certificate for the domain. The domain must already point to this server. It is renewed automatically.'),
    array(null, null, 'How do I back up my website?', 'Go to Advanced &gt; Backup and make a backup of your websites and databases, then download it. You can also have one sent each day to your own FTP server.'),
    array(null, null, 'How do I make my login safer?', 'Turn on two-factor login in Account Information &gt; My Account: after your password, the panel asks for a code from an app on your phone. Keep the recovery codes it shows you somewhere safe.'),
    );
    $fqChanged = 0;
    $fqAdmin = (int) $zdbh->query("SELECT ac_id_pk FROM x_accounts WHERE ac_group_fk = 1 AND ac_deleted_ts IS NULL ORDER BY ac_id_pk LIMIT 1")->fetchColumn();
    foreach ($fqList as $fq) {
        if ($fq[0] !== null) {
            $q = $zdbh->prepare("UPDATE x_faqs SET fq_question_tx = :nq, fq_answer_tx = :na WHERE fq_question_tx = :oq AND fq_answer_tx = :oa AND fq_deleted_ts IS NULL");
            $q->execute(array(':nq' => $fq[2], ':na' => $fq[3], ':oq' => $fq[0], ':oa' => $fq[1]));
            $fqChanged += $q->rowCount();
        } else {
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_faqs WHERE fq_question_tx = :q AND fq_deleted_ts IS NULL");
            $q->execute(array(':q' => $fq[2]));
            if ((int) $q->fetchColumn() === 0 && $fqAdmin > 0) {
                $zdbh->prepare("INSERT INTO x_faqs (fq_acc_fk, fq_question_tx, fq_answer_tx, fq_global_in, fq_created_ts) VALUES (:a, :q, :t, 1, :c)")
                    ->execute(array(':a' => $fqAdmin, ':q' => $fq[2], ':t' => $fq[3], ':c' => time()));
                $fqChanged++;
            }
        }
    }
    $zdbh->exec("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
        VALUES ('faqs_2027_done', 'faqs_2027_done', '1', NULL, 'FAQ given to every group, standard answers brought up to date', 'Sentora Config', 'false')");
    echo "FAQ: available to every group; $fqChanged standard answer(s) brought up to date" . fs_filehandler::NewLine();
}
