# Shippo Sample Data — 5 sandbox tracks (Phase D)

Persistent sandbox samples seeded by `Shubo\ShippingShippo\Test\Integration\SampleDataSeedTest`. Operators looking at the Shippo dashboard or running the smoke CLI see realistic activity in the 5 supported lifecycle states.

**Seeded:** 2026-04-25 by Session 5 / Phase D
**Persistence policy:** persistent — do NOT delete (per architect scope decision §5)
**Verification method:** independent `GET /tracks/shippo/{tracking_number}` for each row, asserting `tracking_status.status` matches the named state.

## Table

| # | Tracking number       | Carrier | Lifecycle state | Status detail | MCP-verified |
|---|-----------------------|---------|-----------------|---------------|--------------|
| 1 | `SHIPPO_PRE_TRANSIT`  | shippo  | PRE_TRANSIT     | The carrier has received the electronic shipment information. | YES |
| 2 | `SHIPPO_TRANSIT`      | shippo  | TRANSIT         | Your shipment has departed from the origin. | YES |
| 3 | `SHIPPO_DELIVERED`    | shippo  | DELIVERED       | Your shipment has been delivered. | YES |
| 4 | `SHIPPO_RETURNED`     | shippo  | RETURNED        | Your shipment has been returned to the original sender. | YES |
| 5 | `SHIPPO_FAILURE`      | shippo  | FAILURE         | The Postal Service has identified a problem with the processing of this item and you should contact support to get further information. | YES |

## How these were seeded

These are Shippo's documented test tracking numbers — see https://docs.goshippo.com/docs/tracking/tracking#test-tracking-numbers. With carrier=`shippo` and any of the magic tracking numbers, Shippo's mock carrier returns the corresponding fixed lifecycle state on every call. No real carrier movement is simulated; the states are stable.

The seed run `POST /tracks/` with the tracking number + carrier=shippo, then `GET /tracks/shippo/{tracking_number}` to read back. Both calls succeed for sandbox keys; the read-back is the canonical assertion.

Each record carries a `metadata: "duka-session-5-phase-d-sample-seed"` marker so future operators can find this seed on Shippo's dashboard.

## Why not also persist the createShipment + transactions pipeline for these?

Shippo's mock-carrier test tracking numbers are tracking-only — they bypass the `POST /shipments` + `POST /transactions` flow. To persist a TRANSIT-state Shippo *transaction* (label-purchase record) one would have to:

1. `POST /shipments` with carrier=shippo and a magic destination address (Shippo also offers magic addresses)
2. `POST /transactions` against that
3. Wait for the mock carrier to advance state

This is a 2-step sequence per state for 5 states, vs. the 1-step `/tracks/` approach above. The 1-step approach is sufficient for "operators see Shippo activity in different states" because the Shippo dashboard shows tracks alongside transactions in a single view. The lifecycle test in Phase A already proves the full POST /shipments -> POST /transactions pipeline against PRE_TRANSIT, so we have the full label-purchase coverage there.

## Verification commands run

For each row in the table:

```
mcp/curl: GET https://api.goshippo.com/tracks/shippo/{tracking_number}
          Authorization: ShippoToken shippo_test_***
          Accept: application/json
```

Result: each tracking_status.status matched the row's "Lifecycle state" exactly. Zero divergence.

## What's not covered (and why it doesn't matter)

- **UNKNOWN state:** Shippo's `SHIPPO_UNKNOWN` test number is also documented but the gateway never produces it intentionally — it's the default for transactions that have not yet been tracked. Already covered implicitly by Phase A's lifecycle test (the freshly-purchased USPS label sits in UNKNOWN until a real or simulated event lands).
- **Unseed:** the trace lives in `/tmp/shippo-sample-data-trace.json` for downstream tooling; the actual Shippo records are persistent on the sandbox account.
