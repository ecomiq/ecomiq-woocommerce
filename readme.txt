=== Ecomiq for WooCommerce ===
Contributors: ecomiq
Tags: woocommerce, marketplace, multichannel, inventory, orders
Requires at least: 6.5
Tested up to: 7.1
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

When you sell a product from this store on another channel, Ecomiq creates the order here with payment and shipping named after the channel where it sold, such as "Shopify". They never show at checkout, charge no shipping, and the buyer gets no emails from your store. An order note says where it was sold, and the Origin column shows the channel.

Add the "Ecomiq" method to a shipping zone and checkout charges the shipping you set up in Ecomiq: one shipment per seller, with its carrier. If Ecomiq does not answer, it charges the fallback rate you set in the zone.

**Disconnect** deletes the API key and the webhooks Ecomiq created. Your products and orders do not change.

= External service =

This plugin connects your store to [Ecomiq](https://ecomiq.pe), an external service. Nothing is sent until you approve a connection. When you approve it, Ecomiq receives a WooCommerce API key with read and write access and the name and address of your store. Ecomiq then uses the WooCommerce REST API to read and update products, stock, prices and orders. If a shipping zone uses the Ecomiq method, checkout sends Ecomiq the cart items (product IDs, SKU, quantity and price) and the shipping address to get the shipping rates.

* [Terms of service](https://ecomiq.pe/terminos)
* [Privacy policy](https://ecomiq.pe/privacidad)

== Frequently Asked Questions ==

= What does Ecomiq need? =

HTTPS and permalinks turned on. The plugin page warns you if either is missing.

= What did Ecomiq change in my store? =

WooCommerce → Ecomiq lists the last 50 changes Ecomiq made, its failed requests and the webhooks that did not reach it. **Full logs** opens the same entries in WooCommerce → Status → Logs, under the source "ecomiq".

= Where is the API key? =

In WooCommerce → Settings → Advanced → REST API, with the description "Ecomiq".

== Changelog ==

= 1.0.0 =
* New - One-click connection with Ecomiq, with approval in WordPress.
* New - Activity log of the changes Ecomiq makes, its failed requests and the webhooks that do not reach it.
* New - Disconnect deletes the API key and the webhooks Ecomiq created.
* New - Diagnostics for HTTPS, permalinks, REST API blocks, firewalls and webhooks, with the IP addresses Ecomiq connects from.
* New - Ecomiq webhooks that WooCommerce disables after failures are turned back on after 15 minutes.
