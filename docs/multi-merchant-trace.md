# Shippo Multi-Merchant Trace (Phase C)

Bidirectional verification that the Shippo adapter produces independent transactions for two distinct merchants in the same test run, and that the per-merchant origin address survives the round-trip to Shippo's records.

Test class: `Shubo\ShippingShippo\Test\Integration\ShippoMultiMerchantTest`
Run command: `vendor/bin/phpunit -c phpunit-integration.xml.dist Test/Integration/ShippoMultiMerchantTest.php`

## What this proves (and what it does not)

PROVES:
- Two `ShipmentRequest` DTOs with distinct `merchantId` (1 and 4) and distinct origin addresses produce two distinct Shippo transactions.
- Each transaction's tracking_number, shippo_transaction_id, label_url, rate, and underlying shipment are unique on Shippo's side (verified via independent HTTP read-back).
- Per-merchant origin (city + zip) survives the round-trip into Shippo's `address_from` for each shipment.
- No field bleed: merchant 1's `tracking_number` does NOT appear on the merchant 4 transaction record and vice versa.

DOES NOT PROVE (intentionally — deferred to Session 6 per architect scope decision §4):
- Per-merchant `shubo_payout_ledger_entry` SHIPPING_FEE_DEBT correctness — that requires the full Magento order chain (commission -> split -> ledger), which requires checkout integration.
- Per-merchant settlement period totals — same dependency.

## Run output (2026-04-25T08:19:33Z)

PHPUnit: `OK (1 test, 24 assertions)` in 9.24 s.

| Field | Merchant 1 | Merchant 4 (Tikha) |
|---|---|---|
| `client_tracking_code` | `multi-m1-647facdd` | `multi-m4-3d692d75` |
| `shippo_transaction_id` | `4d98c0e8aa9b4500b204e85dfd0809ad` | `75008ed1a205414894ea82e9661ee710` |
| `tracking_number` | `9234690396055702759294` | `9234690396055702759300` |
| `origin.city` (input -> Shippo) | San Francisco -> San Francisco | Brooklyn -> Brooklyn |
| `origin.zip` (input -> Shippo+4) | 94117 -> 94117-1832 | 11249 -> 11249-3025 |
| `label_url` | `https://deliver.goshippo.com/4d98c0e8aa9b4500b204e85dfd0809ad.pdf?...` | `https://deliver.goshippo.com/75008ed1a205414894ea82e9661ee710.pdf?...` |

(ZIP+4 enrichment is Shippo's; the gateway does not re-write the customer's input. Both merchants' base ZIPs round-trip exactly.)

## Independent read-back chain

For each transaction the test:

1. `GET /transactions/{object_id}` -> capture `rate` field (rate object_id)
2. `GET /rates/{rate_object_id}` -> capture `shipment` field (shipment object_id)
3. `GET /shipments/{shipment_object_id}` -> capture `address_from` block

This three-hop dereference (different code path than the gateway's POST sequence) gives us the Shippo-canonical view of `address_from.city` and `address_from.zip` for each shipment. The test asserts both differ between the two merchants, AND that they match the merchant's input. Zero divergence found.

## Cleanup

Both transactions were refunded at the end of the test run via `POST /refunds`. No further action required.

## Decision: per-merchant ledger correctness deferred

Per the architect scope decision (`docs/session-5-scope-decision.md` §4), the assertion that each shipment writes a distinct SHIPPING_FEE_DEBT ledger entry under the right `merchant_id` requires the Magento order chain:

```
Magento order placed -> Shubo_Commission calculates split
                     -> Shubo_Payout writes ledger entry
                     -> Shubo_ShippingShippo creates the carrier shipment
                     -> Shubo_Payout writes shipping-fee-debt entry
```

Today, the chain only fires for the legacy flat-rate carrier (`shuboflat`) because Shippo is not selectable at checkout (`MagentoShippingMethod._code` is hardcoded). The Phase C test is therefore the strongest assertion the adapter layer can make in isolation: distinct merchantIds produce distinct independent records on Shippo's side. The ledger assertion lands when Session 6 wires Shippo into checkout.
