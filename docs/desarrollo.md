# Desarrollo

## Entorno local

WordPress con WooCommerce en https://localhost:8443 (usuario `admin`, contraseña `admin`), con el plugin montado desde este repo. El HTTPS lo da un Caddy con un certificado de mkcert: la CA de `mkcert -install` o la de `vite-plugin-mkcert` del admin, así que el navegador y la API local de Ecomiq lo aceptan.

```bash
./dev/setup.sh
```

Para probar con un Ecomiq local, en WooCommerce → Ecomiq → Avanzado pon `https://localhost:3001`.

Los webhooks apuntan a `API_URL` del servidor. En local, llévalos a Api.Admin (`http://host.docker.internal:8004`); `dev/mu-plugins/ecomiq-dev.php` deja a WooCommerce enviarlos a ese host y puerto. La conciliación diaria de Ecomiq los vuelve a `API_URL`.

```bash
./dev/local-webhooks.sh
```

Apagar y borrar el entorno:

```bash
docker compose -f dev/docker-compose.yml down -v
```

## Datos de prueba

Catálogo de prueba: producto simple con galería, producto variable con 4 variantes y fotos por color, categorías anidadas, etiquetas y atributos globales.

```bash
docker compose -f dev/docker-compose.yml run --rm cli wp eval-file wp-content/plugins/ecomiq-for-woocommerce/dev/fixtures/catalog.php <carpeta con mochila-1.png, mochila-2.png, polo.png, polo-rojo.png, polo-azul.png>
```

Carga masiva (productos `BULK-WOO-#####` con marca, color, categoría y foto) y actualización masiva de precio y stock. Conviene pausar los webhooks de Ecomiq mientras se crean.

```bash
docker compose -f dev/docker-compose.yml run --rm cli wp eval-file wp-content/plugins/ecomiq-for-woocommerce/dev/fixtures/bulk.php 1000 1
docker compose -f dev/docker-compose.yml run --rm cli wp eval-file wp-content/plugins/ecomiq-for-woocommerce/dev/fixtures/bulk-update.php 1000 1 1
```

## Build

Zip para instalar en `dist/ecomiq-for-woocommerce.zip`:

```bash
./bin/build.sh
```

Las traducciones salen de `languages/ecomiq-for-woocommerce-es_ES.po`. `bin/translations.py` genera los `.l10n.php` de cada variante de español y avisa si falta algún texto.

## Revisión antes de publicar

```bash
composer install && composer lint
./dev/fresh.sh
./bin/build.sh
```

- `composer lint`: PHPCS con WooCommerce-Core completo (docblocks incluidos) y PHPCompatibility desde PHP 7.4. Plugin Check solo deja el aviso de `load_plugin_textdomain`, que hace falta porque las traducciones van dentro del plugin.
- `./dev/fresh.sh`: instala desde cero en PHP 7.4 + WP 7.0 + WC 11.1 y en PHP 8.4 + WP 7.1 + WC 11.2. Activa, conecta con PKCE, desconecta y desinstala. Falla si queda algo en la base o hay avisos en `debug.log`.

## Versiones

1. Sube la versión en el encabezado de `ecomiq-for-woocommerce.php` (`Version` y la constante `VERSION`) y en `Stable tag` de `readme.txt`.
2. Agrega la entrada en `changelog.txt` con el formato de Woo: `AAAA-MM-DD - version X.Y.Z` y líneas `* New - …`, `* Fix - …`.
3. Sube el tag:

```bash
git tag v1.0.1 && git push origin v1.0.1
```

El workflow `Publish - Release` comprueba que las tres versiones coincidan con el tag, arma el zip y crea el release con las notas del changelog. Ecomiq enlaza siempre a la última versión:

```text
https://github.com/ecomiq/ecomiq-woocommerce/releases/latest/download/ecomiq-for-woocommerce.zip
```

## Tienda de Woo

- `marketplace/`: textos de la ficha, documentación, instrucciones para los revisores e ícono de 160×160.
- No agregar el encabezado `Woo:`; lo pone Woo al publicar.
