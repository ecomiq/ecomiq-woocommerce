#!/bin/sh
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
compose="docker compose -p ecomiq-fresh -f $root/dev/fresh/docker-compose.yml"

"$root/bin/build.sh" >/dev/null
rm -rf "$root/dist/ecomiq-for-woocommerce"
(cd "$root/dist" && unzip -q ecomiq-for-woocommerce.zip)

$compose up -d --wait db 2>/dev/null || { $compose up -d db; sleep 10; }
$compose run --rm cli74 sh /run.sh wp74 7.0.7 11.1.2
$compose run --rm cli84 sh /run.sh wp84 7.1.3 11.2.1
$compose down -v
