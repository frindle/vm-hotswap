#!/usr/bin/env bash
# Build a Slackware .txz package + MD5 for the current version.
#
# Reads the version out of vm-hotswap.plg's <!ENTITY version> line.
# Output lands in archive/<name>-<ver>-noarch-1.txz and .md5
# Rewrites the MD5 entity in vm-hotswap.plg so committers can just
# push the plg + archive without hand-editing the checksum.
#
# Requires: makepkg (from Slackware pkgtools). On macOS this can run
# in the linuxserver/slackpkgtools container if you don't have it
# natively; the CI job installs it into an Ubuntu runner.

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(sed -n 's/.*<!ENTITY[[:space:]]*version[[:space:]]*"\([^"]*\)".*/\1/p' vm-hotswap.plg)
NAME=vm-hotswap
PKG=$NAME-$VERSION-noarch-1

STAGE=$(mktemp -d)
trap "rm -rf $STAGE" EXIT

# Layout matches what the .plg's upgradepkg call expects to unpack under /
mkdir -p "$STAGE/usr/local/emhttp/plugins/$NAME"
cp -a source/$NAME/. "$STAGE/usr/local/emhttp/plugins/$NAME/"

mkdir -p "$STAGE/install"
cat > "$STAGE/install/slack-desc" <<EOF
       |-----handy-ruler------------------------------------------------------|
$NAME: $NAME (Hotswap disk images on Unraid VMs)
$NAME:
$NAME: Attach, detach, and cold-swap disk images on your Unraid VMs from
$NAME: a small web UI under Settings.
$NAME:
$NAME: https://github.com/frindle/$NAME
$NAME:
$NAME:
$NAME:
$NAME:
$NAME:
EOF

mkdir -p archive
OUT="archive/$PKG.txz"

pushd "$STAGE" >/dev/null
if command -v makepkg >/dev/null 2>&1; then
  makepkg -l y -c n "$OLDPWD/$OUT"
else
  # Fallback: tar.xz with the same layout. Works on Unraid because upgradepkg
  # accepts plain .txz tarballs — it just wants xz-compressed tar with the
  # install/ dir at the root.
  tar -cJf "$OLDPWD/$OUT" .
fi
popd >/dev/null

MD5=$(md5sum "$OUT" | awk '{print $1}')
echo "$MD5  $OUT" > "$OUT.md5"

# Patch the MD5 back into vm-hotswap.plg so the download validates.
sed -i.bak "s/<!ENTITY[[:space:]]*MD5[[:space:]]*\"[^\"]*\">/<!ENTITY MD5       \"$MD5\">/" vm-hotswap.plg
rm -f vm-hotswap.plg.bak

echo
echo "Built $OUT"
echo "MD5:  $MD5"
echo "PLG MD5 entity updated. Commit both files."
