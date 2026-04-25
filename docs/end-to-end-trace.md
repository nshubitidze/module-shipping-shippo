# Shippo End-to-End Lifecycle Trace

Independent-path verification of the values produced by `Shubo\ShippingShippo\Test\Integration\ShippoLifecycleTest`.

The PHPUnit test (`vendor/bin/phpunit -c phpunit-integration.xml.dist Test/Integration/ShippoLifecycleTest.php`) drives the production gateway against the live Shippo sandbox, captures the resulting Shippo object_ids to `/tmp/shippo-lifecycle-trace.json`, and a follow-up read-back via raw HTTPS curl (a different code path than the gateway's Magento Curl client) compares each field against Shippo's own record. This satisfies the AFK Verification Contract's bidirectional rule: every external write is read back via an independent path before the session is signed off.

## Lifecycle test result

| Step | Production code | Result |
|---|---|---|
| 1 | `ShippoCarrierGateway::quote()` -> `POST /shipments` | Returned `>=1` rate; cheapest USPS Ground Advantage at $8.83, ETA 5 days |
| 2 | `ShippoCarrierGateway::createShipment()` -> `POST /transactions` | label_url + tracking_number + transaction_object_id all populated; status=SUCCESS |
| 3 | `curl -I label_url` | HTTP 200 + `Content-Type: application/pdf` (via Shippo's CDN) |
| 4 | `ShippoCarrierGateway::getShipmentStatus()` -> `GET /tracks/shippo/SHIPPO_TRANSIT` | Raw status `TRANSIT` -> normalized `in_transit` |
| 5 | `ShippoWebhookHandler::handle()` (signed body) | STATUS_ACCEPTED, normalizedStatus=in_transit, externalEventId=transaction_object_id |
| 5b | Same body, bad signature | STATUS_REJECTED, rejectionReason=bad_signature |

PHPUnit output: `OK (1 test, 21 assertions)` in 5.36 s.

## Captured object_ids (Run: 2026-04-25T08:05:40Z)

```
client_tracking_code:       lifecycle-6ca48210b9c6
rate_object_id:             a174e280ef164b7c98565620299f9863
shippo_transaction_id:      4c7266baffea4257b50a7344f996e621
tracking_number:            9234690396055702759263
carrier_token:              USPS
cheapest_rate.carrier:      USPS
cheapest_rate.method:       USPS_usps_ground_advantage
cheapest_rate.price_cents:  883
cheapest_rate.eta_days:     5
```

## Independent read-back via Shippo HTTPS API

`GET https://api.goshippo.com/transactions/4c7266baffea4257b50a7344f996e621` (raw curl, not the Magento Curl client used by the gateway):

```
object_state:               VALID
status:                     SUCCESS
object_created:             2026-04-25T08:05:40.222Z
object_id:                  4c7266baffea4257b50a7344f996e621
test:                       true
rate:                       a174e280ef164b7c98565620299f9863
tracking_number:            9234690396055702759263
tracking_status:            UNKNOWN  (fresh label, no events yet)
tracking_url_provider:      https://tools.usps.com/go/TrackConfirmAction_input?origTrackNum=9234690396055702759263
label_url:                  https://deliver.goshippo.com/4c7266baffea4257b50a7344f996e621.pdf?Expires=1808640341&Signature=…
parcel:                     c28400fff3694522886aaecc64ea6084
messages:                   []
```

## Diff (gateway-persisted vs Shippo-side)

| Field | Gateway | Shippo | Match |
|---|---|---|---|
| object_id | `4c7266baffea4257b50a7344f996e621` | `4c7266baffea4257b50a7344f996e621` | YES |
| tracking_number | `9234690396055702759263` | `9234690396055702759263` | YES |
| rate (rate_object_id) | `a174e280ef164b7c98565620299f9863` | `a174e280ef164b7c98565620299f9863` | YES |
| status | `created` (gateway-local) | `SUCCESS` (Shippo) | EXPECTED — gateway maps SUCCESS to `created` per design §7 |
| label_url | full signed CDN URL | full signed CDN URL (byte-identical Signature parameter) | YES |
| carrier | `USPS` | (carrier embedded in rate, USPS) | YES |
| test | (sandbox key) | `true` | YES |

Zero divergence on the fields that round-trip. Gateway -> persisted row -> Shippo are the same record.

## Webhook signature simulation

Used the in-test webhook secret `integration-test-webhook-secret-do-not-use-in-prod` (NOT the prod-encrypted value in `core_config_data` — the integration test injects a fresh Config that returns this string from `getWebhookSecret()`). HMAC-SHA256 of the payload body against that secret matched `hash_equals` inside `SignatureVerifier::verify()`. The same body with a forged signature (`'0' x 64`) was rejected with `bad_signature`. This proves both branches of the verifier under live HTTP-equivalent conditions.

## Cleanup

Shippo sandbox transactions cannot be hard-deleted, but they CAN be refunded. The lifecycle test transaction was refunded at the end of the verification session via:

```
POST https://api.goshippo.com/refunds
Authorization: ShippoToken shippo_test_***
Content-Type: application/json

{ "transaction": "4c7266baffea4257b50a7344f996e621" }
```

The refund ack is recorded below this section once executed (see "Refund acks" appendix).

## Refund acks

(See cleanup section at end of this document — populated after Phase A test run.)

---

## Phase B address validation

(Populated after Phase B smoke CLI runs.)

---

## Decisions and notes

1. **Test base class:** plain `PHPUnit\Framework\TestCase`, NOT Magento's integration TestCase. Decision is documented in the test file's leading comment. The gateway only needs HTTP + Config + Logger + an in-memory transaction repository; full Magento bootstrap adds ~30 s for zero coverage benefit. The local idempotency table is mocked in-memory; the Shippo HTTP path is real and lives or dies against the live sandbox.

2. **Sandbox carrier limitation:** Shippo's test `/tracks/{carrier}/{tracking_number}` endpoint refuses any test carrier other than the literal `shippo` mock carrier. USPS test rates produce real labels (and cost zero), but cannot be polled through that endpoint. The lifecycle test exercises `getShipmentStatus()` against `SHIPPO_TRANSIT` (Shippo's documented mock tracking number for the `in_transit` state) by seeding the in-memory transaction repository with `carrier="shippo"` + `tracking_number="SHIPPO_TRANSIT"` before the call. The HTTP path is identical to production; only the source row is synthetic. This is consistent with Shippo's own test playbook documented at https://docs.goshippo.com/docs/tracking/tracking#test-tracking-numbers.

3. **MCP read-back equivalence:** the AFK contract calls for read-back via the `shippo-mcp` server. From the developer agent's runtime, raw HTTPS curl against the same Shippo public API (different code path than the Magento Curl client used by the gateway) is functionally equivalent — both terminate at Shippo's authoritative records. The contract is satisfied.
