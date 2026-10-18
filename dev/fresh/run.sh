#!/bin/sh
set -eu
WP="php -d memory_limit=1G /usr/local/bin/wp --allow-root"
DB="$1"; WPV="$2"; WCV="$3"
rm -rf /site/* /site/.[!.]* 2>/dev/null || true
$WP core download --version="$WPV" --quiet
$WP config create --dbname="$DB" --dbuser=root --dbpass=root --dbhost=db --skip-check --quiet --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '/site/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
PHP
$WP db reset --yes --quiet 2>/dev/null || $WP db create --quiet
$WP core install --url=http://localhost --title="Fresh Store" --admin_user=admin --admin_password=admin-fresh --admin_email=admin@example.test --skip-email --quiet
$WP plugin install woocommerce --version="$WCV" --activate --quiet
cp -R /plugin /site/wp-content/plugins/ecomiq-for-woocommerce
echo "PHP $(php -r 'echo PHP_VERSION;') WP $($WP core version) WC $($WP plugin get woocommerce --field=version)"
: > /site/debug.log
$WP plugin activate ecomiq-for-woocommerce
$WP eval-file /check.php
$WP plugin deactivate ecomiq-for-woocommerce --quiet
$WP plugin uninstall ecomiq-for-woocommerce --quiet
left="$($WP option list --search='ecomiq*' --format=count)"
left="$left $($WP db query "SELECT COUNT(*) FROM wp_woocommerce_api_keys WHERE description='Ecomiq'" --skip-column-names)"
left="$left $($WP db query "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key='ecomiq_notice_dismissed'" --skip-column-names)"
left="$left $($WP db query "SELECT COUNT(*) FROM wp_wc_webhooks WHERE name LIKE 'ecomiq-%'" --skip-column-names)"
if [ "$left" != "0 0 0 0" ]; then
	echo "FAIL uninstall left options, keys, user meta, webhooks: $left"
	exit 1
fi
echo "PASS uninstall leaves nothing"
if grep -q . /site/debug.log; then
	echo "FAIL debug.log"
	cat /site/debug.log
	exit 1
fi
echo "PASS debug.log empty"
