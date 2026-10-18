#!/bin/sh
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
slug=ecomiq-for-woocommerce

python3 "$root/bin/translations.py"

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/$slug"
cp "$root/$slug.php" "$root/readme.txt" "$root/uninstall.php" "$stage/$slug/"
cp -R "$root/includes" "$root/assets" "$root/languages" "$stage/$slug/"

mkdir -p "$root/dist"
rm -f "$root/dist/$slug.zip"
(cd "$stage" && zip -qrX "$root/dist/$slug.zip" "$slug")
echo "$root/dist/$slug.zip"
