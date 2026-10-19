# Recuperación de entregas de webhooks

El plugin conserva la solicitud firmada original cuando una entrega a Ecomiq falla. Action Scheduler programa hasta cinco reintentos adicionales, con esperas de 30, 60, 120, 300 y 900 segundos. Los errores de transporte y HTTP 408, 425, 429 y 5xx se reintentan. Las demás respuestas rechazadas quedan directamente como error terminal.

Los reintentos usan el mismo cuerpo, firma, destino y `X-WC-Webhook-Delivery-ID`. No reconstruyen el producto o pedido ni vuelven a aplicar el mapeo de estados. La identidad combina webhook, identificador de entrega y hash del cuerpo, porque WooCommerce puede repetir el identificador dentro del mismo segundo.

## Revisar y recuperar

En **WooCommerce → Estado → Acciones programadas**, filtra por `ecomiq_retry_webhook_delivery`. Los errores terminales tienen estado **Failed** y grupo `ecomiq-webhook-deliveries`. También puedes listarlos con WP-CLI:

```bash
wp action-scheduler action list --hook=ecomiq_retry_webhook_delivery --status=failed --fields=id,hook,status,group
```

Después de resolver la causa, recupera una acción concreta:

```bash
wp ecomiq webhook retry <action-id>
```

El comando devuelve el ID de una nueva acción pendiente que realiza **un intento**. No inicia otros cinco reintentos. Repetir el comando para la misma acción devuelve la misma recuperación. Si esa recuperación también falla, revisa y recupera su nuevo ID. Action Scheduler no ejecuta directamente las acciones en estado Failed con su comando `action run`.

Una reconexión, cambio de URL o rotación del secreto impide enviar una entrega antigua a otro destino. En ese caso recupera los datos mediante la sincronización de Ecomiq.

Si la base de datos falló al crear la primera acción, la solicitud puede estar conservada sin un ID de Action Scheduler. Lista únicamente los nombres de las opciones y usa su sufijo como clave:

```bash
wp option list --search='ecomiq_webhook_delivery_*' --fields=option_name
wp ecomiq webhook retry --delivery-key=<sufijo-de-64-caracteres>
```

## Datos retenidos

Cada solicitud pendiente se guarda una sola vez en una opción `ecomiq_webhook_delivery_*`, sin autoload. Action Scheduler conserva únicamente su clave y número de intento; sus argumentos tienen un límite de tamaño que no admite todos los pedidos o productos completos.

El cuerpo puede contener datos personales de pedidos. Se elimina al recibir una respuesta 2xx y al desinstalar el plugin. Tras un error terminal se conserva para permitir la recuperación; no se copia al registro de actividad del plugin. No borres la opción ni la acción fallida antes de decidir cómo recuperar la entrega.

WooCommerce conserva su contador de fallos y la reactivación del webhook a los 15 minutos. Reactivar permite futuras entregas; esta cola recupera las entregas que ya fallaron.

## Pruebas

Pruebas aisladas, sin WordPress ni acceso a red/base de datos:

```bash
php dev/tests/webhook-delivery.php
```

El smoke de Action Scheduler requiere un WordPress local con una conexión Ecomiq existente. Usa un webhook temporal y bloquea las peticiones HTTP durante la prueba; elimina sus opciones y acciones al terminar:

```bash
wp eval-file wp-content/plugins/ecomiq-for-woocommerce/dev/tests/webhook-delivery-wordpress.php
```

Estas pruebas verifican la conservación de la identidad. La deduplicación del efecto final corresponde al receptor Ecomiq.
