# Dryden Framework for ZPanelX

*Dryden* is the name of the custom framework for ZPanelX, the framework was built with simplicity in mind for novice developers to enable the rapid development of third-party modules.

Directory structure:
--------------------
* ``ajax``         - AJAX related classes *Added in v10.0.3*
* ``ctrl``         - Controller related classes
* ``db``           - Database drivers
* ``debug``        - Debugging and error logging classes
* ``fs``           - File system classes
* ``runtime``      - Runtime classes
* ``sys``          - System monitoring, versions and tasks
* ``ui``           - User interface classes
* ``ws``           - Web services classes
* ``xml`          - XML classes

Developers resources:
---------------------
A simple developers guide (for those who want to build modules for Sentora) can be found on our website http://docs.sentora.org/?node=9

Notices to accounts:
--------------------
Code can put a notice at the top of an account's panel with ``ctrl_notices`` (the same notices the
Client Notice Manager makes):

```php
// one account; sending again with the same key replaces the notice instead of adding one
ctrl_notices::ToUser($uid, 'Your disk space is **90%** used.', array('type' => 'warning', 'key' => 'quota-90-' . $uid, 'source' => 'my_module'));
// every account, until a time
ctrl_notices::ToEveryone('Maintenance tonight from 02:00 to 04:00.', array('type' => 'maintenance', 'end' => strtotime('tomorrow 04:00')));
// the clients of a reseller
ctrl_notices::ToClientsOf($resellerId, 'New PHP versions are available.');
// take it away
ctrl_notices::Withdraw('quota-90-' . $uid);
```

Types: ``info``, ``warning``, ``maintenance``, ``outage``. Other fields: ``title``, ``start``, ``end``,
``closable`` (false: clients can not close it), ``key``. The hooks ``OnBeforeSendNotice`` and
``OnAfterSendNotice`` run around each save with the notice in ``ctrl_notices::$last``. From the shell:
``sentora-notice send --to user:john --type warning "text"`` (``sentora-notice --help``).
