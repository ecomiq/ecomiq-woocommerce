#!/bin/sh
set -eu

cd "$(dirname "$0")"

cli() {
	docker compose run --rm -T cli wp "$@"
}

if [ ! -f certs/localhost.pem ]; then
	mkdir -p certs
	if command -v mkcert >/dev/null 2>&1; then
		mkcert -cert-file certs/localhost.pem -key-file certs/localhost-key.pem localhost 127.0.0.1 ::1
	else
		CAROOT="$HOME/.vite-plugin-mkcert" "$HOME/.vite-plugin-mkcert/mkcert" -cert-file certs/localhost.pem -key-file certs/localhost-key.pem localhost 127.0.0.1 ::1
	fi
fi

docker compose up -d db wordpress https

until docker compose exec -T wordpress test -f /var/www/html/wp-config.php; do
	sleep 2
done
until cli db check >/dev/null 2>&1; do
	sleep 2
done

if ! cli core is-installed >/dev/null 2>&1; then
	cli core install \
		--url=https://localhost:8443 \
		--title="Tienda de prueba" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.test \
		--skip-email
fi

cli option update home https://localhost:8443
cli option update siteurl https://localhost:8443
cli rewrite structure '/%postname%/'
cli plugin is-installed woocommerce || cli plugin install woocommerce
cli plugin activate woocommerce ecomiq-for-woocommerce
cli option update woocommerce_currency PEN

if [ "$(cli wc product list --user=admin --format=count)" = "0" ]; then
	cli wc product create --user=admin --name="Polo básico" --sku=POLO-001 --regular_price=39.90 --manage_stock=true --stock_quantity=25
	cli wc product create --user=admin --name="Gorra lona" --sku=GORRA-001 --regular_price=29.90 --manage_stock=true --stock_quantity=10
fi

if [ "$(cli wc shop_order list --user=admin --format=count)" = "0" ]; then
	for product in $(cli wc product list --user=admin --field=id); do
		cli wc shop_order create --user=admin --status=processing \
			--billing='{"first_name":"Ana","last_name":"Pérez","email":"ana@example.test","address_1":"Av. Larco 123","city":"Lima","country":"PE"}' \
			--line_items="[{\"product_id\":$product,\"quantity\":1}]"
	done
fi

echo "WordPress: https://localhost:8443/wp-admin (admin / admin)"
