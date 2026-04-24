# Shubo_ShippingShippo — Design Doc (v0.1.0)

First real carrier adapter for `Shubo_ShippingCore`. International-only, sandbox-first (test keys cannot purchase live labels).

## 1. Module Identity
- **Name:** `Shubo_ShippingShippo`
- **Composer:** `shubo/module-shipping-shippo`
- **Version:** `0.1.0`
- **License:** Apache-2.0
- **Namespace:** `Shubo\ShippingShippo`
- **Repo:** https://github.com/nshubitidze/module-shipping-shippo
- **Standalone path:** `/home/nika/module-shipping-shippo/`
- **Mount:** `docker-compose.override.yml` bind → `/var/www/html/app/code/Shubo/ShippingShippo`

## 2. Scope
- **In:** rate quote, label purchase, webhook status, tracking poll, cancel (void label)
- **Out:** COD (Shippo supports only on USPS, not our lane); live labels (deferred — sandbox keys only until live key arrives); Georgia-domestic PUDOs; `listCities()`/`listPudos()` — both return `[]`

## 3. Dependencies
- `Shubo_ShippingCore` (composer: `shubo/module-shipping-core: @dev`)
- `Magento_Sales`, `Magento_Shipping`
- PHP ≥ 8.4 / Magento 2.4.8
- Interfaces consumed (verified):
  - `/home/nika/module-shipping-core/Api/CarrierGatewayInterface.php`
  - `/home/nika/module-shipping-core/Api/WebhookHandlerInterface.php`
  - `/home/nika/module-shipping-core/Model/Carrier/CarrierRegistry.php`
  - `/home/nika/module-shipping-core/Model/Carrier/FlatRateGateway.php` (pattern reference)

## 4. Configuration (`core_config_data`)
| Path | Type | Default | Notes |
|---|---|---|---|
| `shubo_shipping_shippo/api/mode` | `test\|live` | `test` | |
| `shubo_shipping_shippo/api/key` | encrypted string | — | CLI-set only; `shippo_test_*` or `shippo_live_*` |
| `shubo_shipping_shippo/api/webhook_secret` | encrypted string | — | Shared with Shippo when registering webhook |
| `shubo_shipping_shippo/api/enabled` | bool | `0` | Disabled by default |
| `shubo_shipping_shippo/service/allowed_carriers` | csv | `` | Empty = all; e.g. `usps,dhl_express` |
| `shubo_shipping_shippo/service/rate_cache_ttl` | int seconds | `60` | In-request only |

All secrets read via `EncryptorInterface`; never logged or stringified.

## 5. Carrier Code
`shippo` — single token, matches `shuboflat` convention.

## 6. Rate-Quote Flow
```
QuoteRequest → AddressMapper + ParcelMapper (mm→in, g→lb, tetri→decimal via bcmath)
            → POST /shipments
            → filter rates[] by allowed_carriers
            → map rate → RateOption
            → QuoteResponse(options, errors=messages[])
```
- `methodCode = "{provider}_{servicelevel.token}"` (e.g. `usps_priority`)
- `priceCents = (int)bcmul($rate.amount, "100", 0)`
- `etaDays = rate.estimated_days ?? 0`
- `rationale = "shippo-rate-{rate.object_id}"`
- `pudoExternalId = null`
- Cache full `rates[]` in-request keyed by `sha256(serialize(QuoteRequest))` so `createShipment` can reuse without a second API call.

## 7. Label-Purchase Flow
1. Consult `shubo_shipping_shippo_transaction` by `clientTrackingCode` → if hit, return cached `ShipmentResponse` (idempotent).
2. Re-quote internally (uses in-request cache if warm); pick cheapest rate within `allowed_carriers` filter.
3. `POST /transactions { rate, label_file_type: "PDF", async: false }`.
4. Response branching:
   - `status=SUCCESS` → persist row, return `ShipmentResponse(carrierTrackingId=tracking_number, labelUrl=label_url, status="created", raw=response)`
   - `status=QUEUED` → throw `ShipmentDispatchFailedException("Shippo queued — retry")`
   - `status=ERROR` → throw `ShipmentDispatchFailedException(messages[0])`

## 8. Cancel Flow
- Look up `shippo_transaction_id` from local table (fallback: `GET /transactions?tracking_number=`)
- `POST /refunds { transaction }` → map to `CancelResponse(success, carrierMessage, raw)`

## 9. Tracking-Status Flow (poller)
- `GET /tracks/{carrier_token}/{tracking_number}`
- Map `tracking_status.status` via StatusMapper (see §13)
- `StatusResponse(normalizedStatus, carrierStatusRaw=status, occurredAt=status_date, codCollectedAt=null, raw)`

## 10. fetchLabel Flow
- `label_url` stored in `shubo_shipping_shippo_transaction.label_url` at purchase time
- `fetchLabel(carrierTrackingId)` → look up URL → `GET` via `CurlInterface` → `LabelResponse(pdfBytes, "application/pdf", "shippo-{tracking_number}.pdf")`

## 11. listCities / listPudos
Both return `[]`. Destination selection defers to Magento directory. Documented as intentional.

## 12. Webhook Flow
- **Route:** `/rest/V1/shubo-shipping/webhook/shippo` (owned by core `WebhookReceiver`)
- **Registration:** this module's `etc/di.xml` appends `shippo → ShippoWebhookHandler` into `WebhookDispatcher.handlers`
- **Handler contract:** no side effects — parse, verify, return `WebhookResult`. Core applies the status change.
- **Signature check:** `hash_equals(hash_hmac('sha256', $rawBody, $secret), $headers['X-Shippo-Signature'])` → on mismatch return `WebhookResult(STATUS_REJECTED, rejectionReason="bad_signature")`
- **Event filter:** `event !== "track_updated"` → `STATUS_ACCEPTED` no-op with log warn (so Shippo does not retry forever)
- **Normalize & return:** extract `data.tracking_number`, `data.tracking_status.status`, `data.tracking_status.status_date`, `data.object_id`; return `WebhookResult(STATUS_ACCEPTED, carrierTrackingId, normalizedStatus, externalEventId=object_id, occurredAt, rawPayload)`
- **Dedup:** core `WebhookIdempotencyGuard` keyed on `externalEventId`

## 13. Status Enum Map
| Shippo | Core normalized | Terminal |
|---|---|---|
| `UNKNOWN` | `pending` | no |
| `PRE_TRANSIT` | `awaiting_pickup` | no |
| `TRANSIT` | `in_transit` | no |
| `DELIVERED` | `delivered` | yes |
| `RETURNED` | `returned` | yes |
| `FAILURE` | `failed` | yes |

## 14. Failure Handling
| Condition | Exception | Retryable |
|---|---|---|
| cURL/network | `NetworkException` | yes |
| HTTP 401/403 | `AuthException` | no |
| HTTP 429 | `RateLimitedException` | yes (core breaker) |
| HTTP 5xx | 1 retry @ 500 ms, then `TransientHttpException` | yes |
| HTTP 4xx (other) | `ShipmentDispatchFailedException(messages[0])` | no |
| `status=ERROR` on purchase | `ShipmentDispatchFailedException` | no |
| All rates filtered out | `NoCarrierAvailableException` | no |

No new exception types invented.

## 15. Idempotency
- **Rate cache:** in-request array, TTL from config, key `sha256(serialize($QuoteRequest))`. Purely per-request.
- **Transaction cache:** table `shubo_shipping_shippo_transaction`, PK `client_tracking_code`. Consulted first thing in `createShipment`.
- **Webhook:** delegated to core `WebhookIdempotencyGuard` (key = `externalEventId`).

## 16. Money Conversion
- All math via `bcmath`. Never cast tetri to float.
- Tetri → decimal: `bcdiv((string)$cents, "100", 4)`
- Decimal → tetri: `(int)bcmul($amount, "100", 0)`
- 4-decimal boundary precision covers all Shippo-supported currencies.

## 17. db_schema.xml
Single table `shubo_shipping_shippo_transaction`:

| Column | Type | Null | Notes |
|---|---|---|---|
| `client_tracking_code` | varchar(64) | no | PK |
| `shippo_transaction_id` | varchar(64) | no | unique idx |
| `tracking_number` | varchar(128) | no | idx |
| `label_url` | varchar(512) | yes | |
| `status` | varchar(32) | no | last known Shippo status |
| `created_at` | timestamp | no | default CURRENT_TIMESTAMP |

Ship `etc/db_schema_whitelist.json` alongside.

## 18. CLI Smoke Command
`bin/magento shipping_shippo:smoke-rate --from-country=US --from-zip=94107 --to-country=DE --to-zip=10115 --weight-kg=1.2`
- Prints rate table: provider / servicelevel / amount / etaDays
- Used to verify API key + mode without touching orchestrator.

## 19. Security
- API key + webhook secret encrypted via `EncryptorInterface`.
- Test-key floor: `shippo_test_*` cannot purchase real labels — documented safety.
- `hash_equals` timing-safe compare for signatures.
- Redact key + secret from all logs and exception traces (wrap HTTP client logging).

## 20. Test Boundaries
- Unit: all HTTP through a `CurlInterface` mock (same pattern as `Shubo_TbcPayment`, `Shubo_BogPayment`). Zero live calls from PHPUnit.
- Live verification: smoke CLI only.
- Quality gates: PHPStan 8, PHPCS Magento2, PHPUnit — must pass before merge.

## 21. File Tree (Phase 1 deliverable)
```
registration.php
composer.json
LICENSE
NOTICE
README.md
phpunit.xml.dist
phpstan.neon.dist
phpcs.xml.dist
etc/module.xml
etc/di.xml
etc/config.xml
etc/system.xml
etc/db_schema.xml
etc/db_schema_whitelist.json
Api/ShippoTransactionRepositoryInterface.php
Api/Data/ShippoTransactionInterface.php
Model/Adapter/ShippoCarrierGateway.php
Model/Webhook/ShippoWebhookHandler.php
Model/Webhook/SignatureVerifier.php
Model/Service/ShippoClient.php
Model/Service/StatusMapper.php
Model/Service/AddressMapper.php
Model/Service/ParcelMapper.php
Model/Data/Config.php
Model/Data/ShippoTransaction.php
Model/ResourceModel/ShippoTransaction.php
Model/ResourceModel/ShippoTransaction/Collection.php
Model/ShippoTransactionRepository.php
Console/Command/SmokeRateCommand.php
Test/Unit/bootstrap.php
Test/Unit/**/*Test.php
```
(`webapi.xml` intentionally omitted — core owns the webhook route.)

## 22. Open Questions / Follow-ups
- Live-key rollout: gated on finance sign-off (Shippo billing + USD float).
- Wolt adapter lands later in a sister repo (`module-shipping-wolt`) with **zero changes** to `Shubo_ShippingCore` or this module. This is the closing invariant that proves the framework is adapter-agnostic.

---

## Phase 1 Build Checklist
- [ ] `registration.php`, `composer.json`, `etc/module.xml` (skeleton boots under bind-mount)
- [ ] `etc/config.xml`, `etc/system.xml` (all 6 config paths from §4)
- [ ] `etc/db_schema.xml` + `db_schema_whitelist.json` (table per §17)
- [ ] `Api/Data/ShippoTransactionInterface.php` + `Api/ShippoTransactionRepositoryInterface.php`
- [ ] `Model/Data/ShippoTransaction.php` + ResourceModel + Collection + Repository
- [ ] `Model/Data/Config.php` (ScopeConfig + Encryptor wrapper)
- [ ] `Model/Service/ShippoClient.php` (CurlInterface-backed, redacting)
- [ ] `Model/Service/AddressMapper.php` (ContactAddress → Shippo address)
- [ ] `Model/Service/ParcelMapper.php` (mm→in, g→lb, tetri→decimal)
- [ ] `Model/Service/StatusMapper.php` (enum table from §13)
- [ ] `Model/Adapter/ShippoCarrierGateway.php` (implements CarrierGatewayInterface, 8 methods)
- [ ] `Model/Webhook/SignatureVerifier.php` (HMAC-SHA256, `hash_equals`)
- [ ] `Model/Webhook/ShippoWebhookHandler.php` (implements WebhookHandlerInterface)
- [ ] `etc/di.xml` — register gateway in `CarrierRegistry`, handler in `WebhookDispatcher`
- [ ] `Console/Command/SmokeRateCommand.php`
- [ ] Unit tests: AddressMapper, ParcelMapper, StatusMapper, SignatureVerifier, ShippoCarrierGateway (with CurlInterface mock), ShippoWebhookHandler
- [ ] PHPStan 8, PHPCS, PHPUnit all green inside Docker
- [ ] README with smoke-CLI usage + webhook registration steps
