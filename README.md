# Shubo_ShippingShippo

First real carrier adapter for [`Shubo_ShippingCore`](https://github.com/nshubitidze/module-shipping-core). Integrates [Shippo](https://goshippo.com) as a multi-carrier aggregator — one API key gives access to USPS, UPS, FedEx, DHL, and dozens of regional carriers.

Apache 2.0 · Magento 2.4.8 · PHP ≥ 8.1.

## What it does

- **Rate quote** — POST `/shipments` → returns all eligible rates, filtered by your allowed-carriers config.
- **Label purchase** — POST `/transactions` → picks cheapest (or the operator's preferred carrier) and persists the Shippo transaction id for idempotent retries.
- **Tracking** — webhook handler for `track_updated` events + poller fallback via GET `/tracks/{carrier}/{tracking}`.
- **Cancel** — POST `/refunds` to void a purchased label.
- **Signature verification** — HMAC-SHA256 with `hash_equals` timing-safe compare; empty signatures rejected.

## What it does NOT do

- COD (Shippo supports it only on USPS, not relevant for international shipments from Georgia).
- Georgia-domestic delivery (use a domestic carrier adapter — Wolt Drive or Trackings.ge when those adapters land).
- `listCities()` / `listPudos()` — both return `[]`. Shippo does not expose a city or PUDO API.

## Install

This module is usually consumed through the main duka marketplace via composer VCS. Standalone:

```bash
composer require shubo/module-shipping-shippo:@dev
bin/magento module:enable Shubo_ShippingShippo
bin/magento setup:upgrade
```

Or, for local development on this module inside a duka container, bind-mount the standalone repo. See `/home/nika/duka/docker-compose.override.yml` for the pattern.

## Configure

Set the API key + webhook secret via CLI. Test keys start with `shippo_test_*` and cannot purchase real labels — the project's spending-safety floor.

```bash
bin/magento config:set shubo_shipping_shippo/api/mode test
bin/magento config:set shubo_shipping_shippo/api/enabled 1
bin/magento config:set shubo_shipping_shippo/api/key "shippo_test_xxxxxxxxxxxx"
bin/magento config:set shubo_shipping_shippo/api/webhook_secret "$(openssl rand -hex 32)"
bin/magento cache:flush config
```

For live rollout, save the `shippo_live_*` key through the admin UI (Stores → Configuration → Sales → Shippo Carrier) so the Encrypted backend model encrypts it at rest. Live mode hard-fails if it reads a plaintext value from `core_config_data`.

## Register the webhook

```bash
curl -X POST -H "Authorization: ShippoToken $SHIPPO_KEY" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://<your-host>/rest/V1/shubo-shipping/webhook/shippo","event":"track_updated","is_test":true}' \
  https://api.goshippo.com/webhooks/
```

The webhook URL is owned by `Shubo_ShippingCore`'s `WebhookReceiver` — this module only registers the handler inside the core dispatcher.

## Smoke test (live sandbox)

```bash
bin/magento shipping_shippo:smoke-rate \
  --from-country=US --from-state=CA --from-city="San Francisco" --from-zip=94107 \
  --to-country=US --to-state=NY --to-city="New York" --to-zip=10001 \
  --weight-kg=0.5
```

Expected output (sandbox):

```
Provider        Method                      Price     ETA  Rationale
--------------------------------------------------------------------------------
USPS            USPS_usps_ground_advantage  8.83      5d   shippo-rate-<id>
UPS             UPS_ups_ground_saver        9.18      5d   shippo-rate-<id>
...
```

## Status enum map (Shippo → ShippingCore normalized)

| Shippo         | ShippingCore      | Terminal |
|----------------|-------------------|----------|
| `UNKNOWN`      | `pending`         | no       |
| `PRE_TRANSIT`  | `awaiting_pickup` | no       |
| `TRANSIT`      | `in_transit`      | no       |
| `DELIVERED`    | `delivered`       | yes      |
| `RETURNED`     | `returned`        | yes      |
| `FAILURE`      | `failed`          | yes      |

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/phpcs
```

Tests mock `CurlFactory` — no live Shippo calls from PHPUnit. Live verification goes through the smoke CLI only.

## Cost note

Shippo charges ~$0.05 per purchased label on top of the carrier's posted rate. This lands in the buyer/merchant's shipping cost, not Shubo's margin.

## Design doc

`docs/design.md` — ~200 lines, authoritative spec for the 10 core flows + failure handling + idempotency + security.
