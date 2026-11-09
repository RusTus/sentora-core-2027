# Notes for Claude (and anyone releasing Sentora-2027)

This repository is the panel's core. Its sister repository, RusTus/sentora-installers-2027, holds
the installer, the add-on repository (sentora-repo/) and the feedback server; both are released
together.

## Version numbers: the year never rolls over

- The version is in `VERSION`: `2027.N`, where N simply counts up by one for every release.
- **2027 stays 2027.** After 2027.99 comes **2027.100**, then 2027.101, 2027.102 and so on. It never
  becomes 2028 (or 2027.9.9, or anything else): the 2027 is the product's name, not a date.
- The tooling handles this: the release workflow sorts versions with `sort -V`, and `sentora-update`
  knows a new release by its SHA-256, not by comparing numbers.

## Releasing (only when the owner asks)

1. `php .github/scripts/stamp-rustus.php` (in this repository).
2. Write the next version into `VERSION` (one more than the last: see the newest `- Release 2027.N`
   line in CHANGELOG.md).
3. In CHANGELOG.md of **both** repositories, add `- Release 2027.N: <what it brings>` above the
   previous release line (feature bullets go above that line too, as they are made).
4. Commit "Release 2027.N" in both repositories, push, and fast-forward `master2027` in both.
5. Run the `release.yml` workflow on `master2027` in both repositories.
6. Check: both runs succeeded on the release commits; the `v2027.N` release and the `current`
   release ("Sentora-2027.N (current)") exist in both; the core zip's VERSION says 2027.N.

## Standing rules from the owner

- Explain in plain words; check (test) before saying something works.
- Add-on modules (sentora-repo/*.zpp) must install and repair themselves without changes to the core.
- Changing the panel's own host name stays an SSH-only command (sentora-change-domain).
- When something is less secure, it is not done that way.
- Only ask questions when the answer is really needed.
