# Contributing to Sentora-2027

Thanks for helping. This build targets **Ubuntu 26.04 only**, with PHP 8.5, MySQL 8.4 and Apache 2.4.

## Reporting bugs and asking for features

Use the issue templates (**Issues > New issue**). For a security problem, follow the [security policy](../SECURITY.md) instead of opening an issue.

## Making changes

The code lives in two repositories, both on the branch `master2027`:

- [RusTus/sentora-core-2027](https://github.com/RusTus/sentora-core-2027): the panel
- [RusTus/sentora-installers-2027](https://github.com/RusTus/sentora-installers-2027): service configuration, SQL and build sources

1. Branch from `master2027`, and open the pull request against `master2027`. Keep each pull request to one change.
2. Test on a fresh Ubuntu 26.04 install, then run `sentora-post-install-check` and `sentora-smoke-test`.
3. Add a line to [CHANGELOG.md](../CHANGELOG.md) describing the change.

## Rules every module follows

- Check that every id or name taken from a form or the address belongs to the logged-in account (`ctrl_ownership::Owns`, `ctrl_ownership::SafeSubFolder`) before using it.
- Use bound parameters for every SQL value; never build SQL from form input.
- Escape everything shown on a page (`htmlspecialchars`).
- Don't show server paths to clients; don't follow symbolic links in account folders.
- Actions are form posts; the CSRF token is added and checked centrally.
- Admin-only modules check the user's group in the controller, not only through module permissions.
- Shell scripts pass `shellcheck` and keep lines to 80 columns.
