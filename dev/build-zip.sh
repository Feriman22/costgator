#!/usr/bin/env bash
# Builds dist/costgator-<version>.zip with only the plugin files.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' costgator/costgator.php)
stable=$(sed -n 's/^Stable tag: *//p' costgator/readme.txt)
[ "$version" = "$stable" ] || { echo "Version $version != Stable tag $stable" >&2; exit 1; }
mkdir -p dist
out="dist/costgator-$version.zip"
rm -f "$out"
zip -qr "$out" costgator -x '*/.*' '*/vendor/*' '*/node_modules/*'
unzip -l "$out" | tail -n +4 | head -n -2 | awk '{print $4}'
echo "Built $out ($(du -h "$out" | cut -f1))"
