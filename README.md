# Ecomiq para WooCommerce

Plugin de WordPress que conecta una tienda WooCommerce con Ecomiq sin copiar claves de API.

- **WooCommerce → Ecomiq**: conectar, aprobar, ver el estado (tienda en Ecomiq, último acceso, clave) y desconectar.
- **API REST `ecomiq/v1`**: `GET status` y `POST token`.
- **Desconectar** borra la clave de API y los webhooks que creó Ecomiq. Productos y pedidos no cambian.
- **Diagnóstico**: HTTPS, enlaces permanentes, que la API REST responda (y quién la bloquea: Cloudflare, Wordfence, Sucuri, ModSecurity…), que el servidor pueda salir hacia Ecomiq, que Ecomiq haya entrado en las últimas 2 horas, webhooks pausados o desactivados, y plugins de seguridad activos con dónde permitir a Ecomiq. Muestra las IP desde las que se conecta Ecomiq (las envía al conectar, `OUTBOUND_IPS` en el servidor).
- **Webhooks desactivados**: WooCommerce apaga un webhook tras 5 entregas fallidas seguidas. El plugin lo registra en Actividad y lo vuelve a activar a los 15 minutos (solo los de Ecomiq desactivados por fallas).
- **Actividad**: las últimas 50 escrituras de Ecomiq (productos, stock, precios, pedidos, webhooks), sus solicitudes fallidas y los webhooks que no llegaron. Las lecturas no se anotan; las cubre «Último acceso». Todo va también al registro de WooCommerce con origen `ecomiq` (Estado → Registros).

Requisitos: WordPress 6.5, PHP 7.4, WooCommerce 9.0, HTTPS y enlaces permanentes.

Licencia: GPL-2.0-or-later (`LICENSE`).

## Conexión

OAuth 2.0 con PKCE (S256). El plugin hace de servidor de autorización y Ecomiq de cliente.

1. Ecomiq lee `GET {tienda}/wp-json/ecomiq/v1/status`: `authorize_url`, `rest_url`, `server` y `https`.
2. Ecomiq lleva al navegador a `authorize_url` con `redirect_uri`, `state`, `code_challenge`, `code_challenge_method=S256` y `store` (nombre de la tienda en Ecomiq, solo para mostrar).
3. El plugin solo acepta `redirect_uri` igual a `{server}/connect/woocommerce`. `server` es la dirección de Ecomiq en *Avanzado* (por defecto `https://admin.ecomiq.pe`).
4. Al aprobar vuelve a `redirect_uri?code=…&state=…`; al cancelar, con `error=access_denied`. El código vence a los 10 minutos y sirve una sola vez.
5. Ecomiq canjea con `POST {rest_url}ecomiq/v1/token` y `{code, code_verifier, redirect_uri, store_name, store_url}`. Recibe `{consumer_key, consumer_secret, key_id, permissions, name, home_url, rest_url}`.
6. Hay una clave por tienda: reconectar crea una nueva y borra la anterior.

El canje exige HTTPS, igual que la autenticación básica de WooCommerce. Solo un WordPress con `WP_ENVIRONMENT_TYPE` = `local` lo acepta sin HTTPS.

## Desarrollo

WordPress con WooCommerce en https://localhost:8443 (usuario `admin`, contraseña `admin`), con el plugin montado desde este repo. El HTTPS lo da un Caddy con un certificado de mkcert: la CA de `mkcert -install` o la de `vite-plugin-mkcert` del admin, así que el navegador y la API local de Ecomiq lo aceptan.

```bash
./dev/setup.sh
```

Para probar con un Ecomiq local, en WooCommerce → Ecomiq → Avanzado pon `https://localhost:3001`.

Los webhooks apuntan a `API_URL` del servidor. En local, llévalos a Api.Admin (`http://host.docker.internal:8004`); `dev/mu-plugins/ecomiq-dev.php` deja a WooCommerce enviarlos a ese host y puerto. La conciliación diaria de Ecomiq los vuelve a `API_URL`.

```bash
./dev/local-webhooks.sh
```

Carga masiva (productos `BULK-WOO-#####` con marca, color, categoría y foto) y actualización masiva de precio y stock; conviene pausar los webhooks de Ecomiq mientras se crean:

```bash
docker compose -f dev/docker-compose.yml run --rm cli wp eval-file wp-content/plugins/ecomiq-for-woocommerce/dev/fixtures/bulk.php 1000 1
docker compose -f dev/docker-compose.yml run --rm cli wp eval-file wp-content/plugins/ecomiq-for-woocommerce/dev/fixtures/bulk-update.php 1000 1 1
```

Catálogo de prueba (producto simple con galería, producto variable con 4 variantes y fotos por color, categorías anidadas, etiquetas y atributos globales):

```bash
docker compose -f dev/docker-compose.yml run --rm cli wp eval-file wp-content/plugins/ecomiq-for-woocommerce/dev/fixtures/catalog.php <carpeta con mochila-1.png, mochila-2.png, polo.png, polo-rojo.png, polo-azul.png>
```

Zip para instalar (`dist/ecomiq-for-woocommerce.zip`):

```bash
./bin/build.sh
```

Las traducciones salen de `languages/ecomiq-for-woocommerce-es_ES.po`; `bin/translations.py` genera los `.l10n.php` de cada variante de español y avisa si falta algún texto.

Apagar y borrar el entorno:

```bash
docker compose -f dev/docker-compose.yml down -v
```

## Publicación

Antes de subir una versión a la tienda de Woo:

```bash
composer install && composer lint
./dev/fresh.sh
./bin/build.sh
```

- `composer lint`: PHPCS con WooCommerce-Core completo (docblocks incluidos) y PHPCompatibility desde PHP 7.4. Plugin Check solo deja el aviso de `load_plugin_textdomain`, que hace falta porque las traducciones van dentro del plugin.
- `./dev/fresh.sh`: instala desde cero en PHP 7.4 + WP 7.0 + WC 11.1 y en PHP 8.4 + WP 7.1 + WC 11.2. Activa, conecta con PKCE, desconecta y desinstala. Falla si queda algo en la base o hay avisos en `debug.log`.
- `changelog.txt` sigue el formato de Woo (`AAAA-MM-DD - version X.Y.Z`, `* New - …`). Cada versión sube `Version`, `VERSION`, `Stable tag` y el changelog.
- `marketplace/`: textos de la ficha, documentación, instrucciones para los revisores e ícono de 160×160.
- No agregar el encabezado `Woo:`; lo pone Woo al publicar.
