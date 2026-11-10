#!/bin/bash
# Put the OWASP Core Rule Set (CRS) used by ModSecurity into etc/modsecurity/coreruleset/: a release
# tag, checked against the commit it must point to (both from the release page of
# https://github.com/coreruleset/coreruleset; use the LTS line, e.g. 4.25.x).
#   .github/scripts/update-crs.sh 4.25.2 b9818c3718bc75b99403aed6c4b23773004c5412
set -eu
VER=${1:?version}; COMMIT=${2:?commit}
HERE=$(cd "$(dirname "$0")/../.." && pwd)
DEST="$HERE/etc/modsecurity/coreruleset"
W=$(mktemp -d); trap 'rm -rf "$W"' EXIT
git -c advice.detachedHead=false clone -q --depth 1 --branch "v$VER" https://github.com/coreruleset/coreruleset.git "$W/crs"
[ "$(git -C "$W/crs" rev-parse HEAD)" = "$COMMIT" ] || { echo "v$VER is not commit $COMMIT: stopped"; exit 1; }
rm -rf "$DEST"; mkdir -p "$DEST/rules"
cp "$W/crs/rules/"*.conf "$W/crs/rules/"*.data "$DEST/rules/"
cp "$W/crs/crs-setup.conf.example" "$W/crs/LICENSE" "$DEST/"
printf 'OWASP CRS %s (git commit %s), from https://github.com/coreruleset/coreruleset\nUpdated with .github/scripts/update-crs.sh; used by the Web Hardening add-on (ModSecurity).\n' "$VER" "$COMMIT" > "$DEST/VERSION"
echo "CRS $VER in $DEST"
