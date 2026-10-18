# Woo Marketplace listing

Textos para la ficha en woocommerce.com. Van en inglés porque la tienda de Woo está en inglés.

## Product

- **Name:** Ecomiq for WooCommerce
- **Type:** Extension, free (billing happens in Ecomiq)
- **Icon:** `icon.png` (160×160, no rounded corners)
- **Highlight card color:** `#000000`
- **Categories:** Store management, Marketplaces, Inventory

## Short description

Sync products, stock, prices and orders between WooCommerce and your other sales channels with Ecomiq. Connect in one click, without copying API keys.

## Long description

Ecomiq manages your catalog, stock and orders in one place and keeps them in sync with WooCommerce and your marketplaces.

- **One-click connection.** Approve Ecomiq in WordPress. No API keys to copy or paste.
- **Your store as source or channel.** Bring your WooCommerce products into Ecomiq, or sell in WooCommerce what you manage in Ecomiq.
- **Stock and prices in sync.** WooCommerce tells Ecomiq about changes through webhooks, and Ecomiq updates WooCommerce through its REST API, with scheduled checks as backup.
- **Orders in one place.** WooCommerce orders appear in Ecomiq next to orders from your other channels.
- **See what changed.** WooCommerce → Ecomiq lists every change Ecomiq made to your store and any failed request.

### How it works

1. Install and activate the extension.
2. Go to WooCommerce → Ecomiq and click **Connect**, or paste your store address in Ecomiq.
3. Approve the connection. Ecomiq receives a WooCommerce REST API key for your store and starts syncing.

**Disconnect** deletes the API key and the webhooks Ecomiq created. Your products and orders do not change.

### Requirements

- An Ecomiq account.
- HTTPS and permalinks turned on.

## FAQ

**Do I need an Ecomiq account?**
Yes. The extension connects your store to Ecomiq. Sign up at ecomiq.pe.

**What access does Ecomiq get?**
A WooCommerce REST API key with read and write access, shown in WooCommerce → Settings → Advanced → REST API with the description "Ecomiq".

**Can I see what Ecomiq changed?**
Yes. WooCommerce → Ecomiq lists the last 50 changes. The full log is in WooCommerce → Status → Logs under the source "ecomiq".

**What happens if I disconnect?**
Ecomiq stops syncing. The API key and webhooks are deleted. Products and orders stay as they are.

## Documentation

### Setup

1. Make sure the store uses HTTPS and permalinks are on (Settings → Permalinks, any option except Plain).
2. Install and activate Ecomiq for WooCommerce.
3. Go to WooCommerce → Ecomiq and click **Connect**. Sign in to Ecomiq if asked and choose the Ecomiq store.
4. Choose how Ecomiq uses the store: **To bring products** (WooCommerce is the source) or **To sell** (WooCommerce is a sales channel). A store has one use at a time.
5. Approve the connection in WordPress. You return to Ecomiq with the store connected.

### Activity

WooCommerce → Ecomiq shows:

- Last access: the last time Ecomiq used its API key.
- Activity: the last 50 changes Ecomiq made (products, stock, prices, orders, webhooks), failed requests and webhooks that did not reach Ecomiq.
- Full logs: WooCommerce → Status → Logs, source "ecomiq".

### Troubleshooting

| Message | Fix |
| --- | --- |
| WordPress does not detect HTTPS | Serve the site over HTTPS. If it runs behind a proxy, make sure WordPress sees the request as HTTPS. |
| Turn on permalinks | Settings → Permalinks, choose any option except Plain. |
| Invalid request | The link is incomplete or expired. Start again from Ecomiq. |
| Ecomiq shows "No access" | The API key was deleted or revoked. Connect again from WooCommerce → Ecomiq. |

### Uninstall

Deleting the extension disconnects the store and removes its settings, activity and notice preferences.

## Testing instructions for reviewers

1. Install the extension on a site with HTTPS and permalinks.
2. Sign in to Ecomiq with the test account below.
3. In WooCommerce → Ecomiq, click **Connect**, choose the test store in Ecomiq and pick **To bring products**.
4. Approve in WordPress. Ecomiq shows the channel as connected and lists the WooCommerce products to import.
5. Change the stock of a product in Ecomiq. WooCommerce → Ecomiq shows "Updated stock".
6. Click **Disconnect** in WooCommerce → Ecomiq. The API key and webhooks are removed.

Test account: _pendiente (cuenta de prueba en admin.ecomiq.pe)_

## Gallery (896×550 or larger, English UI)

Capture after Ecomiq is live in production, with a store in English:

1. WooCommerce → Ecomiq, not connected (Connect card).
2. The approval screen in WordPress.
3. Ecomiq: channel connected, products ready to import.
4. WooCommerce → Ecomiq, connected, with Activity.
