=== Ecomiq for WooCommerce ===
Contributors: ecomiq
Tags: woocommerce, marketplace, multichannel, inventory, orders
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store to Ecomiq in one click.

== Description ==

Ecomiq keeps products, stock, prices and orders in sync between your WooCommerce store and your other sales channels.

This plugin replaces copying API keys by hand:

1. Click **Connect** in WooCommerce → Ecomiq, or paste your store address in Ecomiq.
2. Approve the connection in your WordPress.
3. Ecomiq receives a WooCommerce API key for your store and starts syncing.

**Disconnect** deletes the API key and the webhooks Ecomiq created. Your products and orders do not change.

= External service =

This plugin connects your store to [Ecomiq](https://ecomiq.pe), an external service. Nothing is sent until you approve a connection. When you approve it, Ecomiq receives a WooCommerce API key with read and write access and the name and address of your store. Ecomiq then uses the WooCommerce REST API to read and update products, stock, prices and orders.

* [Terms of service](https://ecomiq.pe/terminos)
* [Privacy policy](https://ecomiq.pe/privacidad)

== Frequently Asked Questions ==

= What does Ecomiq need? =

HTTPS and permalinks turned on. The plugin page warns you if either is missing.

= Where is the API key? =

In WooCommerce → Settings → Advanced → REST API, with the description "Ecomiq".

== Changelog ==

= 1.0.0 =
* One-click connection with Ecomiq.
