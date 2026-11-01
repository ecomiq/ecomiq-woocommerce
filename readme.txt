=== Ecomiq for WooCommerce ===
Contributors: ecomiq
Tags: woocommerce, marketplace, multichannel, inventory, orders
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
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

When an order has products from several sellers, or yours and a seller's, the Ecomiq box on the order shows each part with its products, status and tracking. Each line says who ships it.

Diagnostics says whether WooCommerce fulfillments are on, where Ecomiq records what each seller shipped, and has a button to turn them on. They are a WooCommerce beta and never turn on by themselves.

When Ecomiq already told the buyer about its shipments through fulfillments, the plugin does not send WooCommerce's "Completed order" email, so she gets no second email. Fulfillments the store creates do not count. Without Ecomiq fulfillments, or with fulfillments off, the email is sent as usual.

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

= What is the _ecomiq meta on order lines? =

Ecomiq marks the products it publishes with the `_ecomiq` meta. When an order is placed, the plugin copies it to the order lines of those products, so Ecomiq knows which lines it sold. It is hidden on the order screen and in emails.

== Changelog ==

= 1.3.0 =
* New - WordPress says when a new version is out, on the Plugins screen and in WooCommerce → Ecomiq, and installs it with "Update now" or with automatic updates. The connection with Ecomiq stays.
* New - "View details" shows what changed in the new version.

= 1.2.0 =
* New - Order lines for products Ecomiq published keep its hidden `_ecomiq` mark, from classic and block checkout, the REST API and the order screen, so Ecomiq knows which lines it sold.
* New - Orders with products from several sellers show each part in the Ecomiq box on the order, with its products, status and tracking. Each line says who ships it.
* New - Diagnostics says whether WooCommerce fulfillments are on and has a button to turn them on. They never turn on by themselves.
* New - When Ecomiq already told the buyer about its shipments through fulfillments, WooCommerce does not send the "Completed order" email again.
* New - The status endpoint reports the WooCommerce version and whether fulfillments are on.

= 1.1.0 =
* New - "Ecomiq" shipping method for shipping zones: checkout charges the rates Ecomiq quotes, one shipment per seller, with a fallback rate when Ecomiq does not answer.
* New - Orders Ecomiq creates for sales on other channels carry payment and shipping named after that channel, send no emails to the buyer and note where they were sold.
* New - Ecomiq reads the store's order statuses, including those other plugins add, to map them.
* New - Ecomiq webhooks that fail are retried after 30 seconds and 1, 2, 5 and 15 minutes, and can be retried with `wp ecomiq webhook retry`.
* New - Disconnecting, or connecting to another Ecomiq store, tells the previous one and removes its webhooks.

= 1.0.0 =
* New - One-click connection with Ecomiq, with approval in WordPress.
* New - Activity log of the changes Ecomiq makes, its failed requests and the webhooks that do not reach it.
* New - Disconnect deletes the API key and the webhooks Ecomiq created.
* New - Diagnostics for HTTPS, permalinks, REST API blocks, firewalls and webhooks, with the IP addresses Ecomiq connects from.
* New - Ecomiq webhooks that WooCommerce disables after failures are turned back on after 15 minutes.
