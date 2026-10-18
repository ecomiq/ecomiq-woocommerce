#!/bin/sh
set -eu

cd "$(dirname "$0")"

cli() {
	docker compose run --rm -T cli wp "$@"
}

docker compose up -d db wordpress

until docker compose exec -T wordpress test -f /var/www/html/wp-config.php; do
	sleep 2
done
until cli db check >/dev/null 2>&1; do
	sleep 2
done

if ! cli core is-installed >/dev/null 2>&1; then
	cli core install \
		--url=http://localhost:8888 \
		--title="Tienda de prueba" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.test \
		--skip-email
fi

cli rewrite structure '/%postname%/'
cli plugin is-installed woocommerce || cli plugin install woocommerce
cli plugin activate woocommerce ecomiq-for-woocommerce
cli option update woocommerce_currency PEN

if [ "$(cli wc product list --user=admin --format=count)" = "0" ]; then
	cli wc product create --user=admin --name="Polo básico" --sku=POLO-001 --regular_price=39.90 --manage_stock=true --stock_quantity=25
	cli wc product create --user=admin --name="Gorra lona" --sku=GORRA-001 --regular_price=29.90 --manage_stock=true --stock_quantity=10
fi

echo "WordPress: http://localhost:8888/wp-admin (admin / admin)"
