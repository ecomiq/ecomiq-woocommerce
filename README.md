# Ecomiq para WooCommerce

Plugin de WordPress que conecta una tienda WooCommerce con Ecomiq sin copiar claves de API.

- **WooCommerce → Ecomiq**: conectar, aprobar, ver el estado (tienda en Ecomiq, último acceso, clave) y desconectar.
- **API REST `ecomiq/v1`**: `GET status` y `POST token`.
- **Desconectar** borra la clave de API y los webhooks que creó Ecomiq. Productos y pedidos no cambian.

Requisitos: WordPress 6.5, PHP 7.4, WooCommerce 9.0, HTTPS y enlaces permanentes.

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

WordPress con WooCommerce en http://localhost:8888 (usuario `admin`, contraseña `admin`), con el plugin montado desde este repo:

```bash
./dev/setup.sh
```

Para probar con un Ecomiq local, en WooCommerce → Ecomiq → Avanzado pon `https://localhost:3001`.

Zip para instalar (`dist/ecomiq-for-woocommerce.zip`):

```bash
./bin/build.sh
```

Las traducciones salen de `languages/ecomiq-for-woocommerce-es_ES.po`; `bin/translations.py` genera los `.l10n.php` de cada variante de español y avisa si falta algún texto.

Apagar y borrar el entorno:

```bash
docker compose -f dev/docker-compose.yml down -v
```
