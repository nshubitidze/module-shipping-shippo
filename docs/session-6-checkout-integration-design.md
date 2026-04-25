# Session 6 — Shippo Checkout Integration Design (Architect Sign-off)

**Author:** Architect
**Date:** 2026-04-25
**Predecessor:** `docs/session-5-scope-decision.md` (Path B; adapter end-to-end MCP-verified)
**Module under change:** `Shubo_ShippingShippo` (standalone canonical: `~/module-shipping-shippo/`)
**Companion modules touched (read + minor wiring):** `Shubo_ShippingCore`, `Shubo_Merchant`
**Estimated work:** 6-10 hours including tests, broken into 4 sub-phases

---

## 1. What Session 5 left on the table

Session 5 proved the Shippo adapter end-to-end against the live sandbox:
quote → label purchase → tracking poll → webhook ingestion → idempotency
guard, with bidirectional MCP read-back at every external write. Five
sandbox transactions are persisted as named-state samples and a working
`AddressValidator` service ships behind a CLI smoke command.

What Session 5 did **not** ship, by explicit scope decision:

- Shippo as a selectable carrier at checkout
- Admin-side "Ship" button → Shippo carrier dispatch → label purchase
- Customer-facing tracking visibility for Shippo shipments
- Per-merchant ledger entries written through the real `Magento_Shipment →
  Shubo_ShippingCore observer → Shubo_Payout` chain (Phase C asserted only
  Shippo-side independence, not the duka-side ledger)
- Address validator wired into the checkout / admin / GraphQL flow

All five gaps share the same root cause: `Shubo\ShippingCore\Model\Carrier\MagentoShippingMethod`
is hardcoded to `_code = 'shuboflat'` and to a single `RateQuoteServiceInterface`
binding. There is no `<carriers><shippo>` block in any module's `config.xml`.
There is no carrier subclass for Shippo. The address validator has no caller
because the checkout flow never reaches a Shippo rate quote.

Session 6 closes all five gaps in one coherent change.

---

## 2. Architectural decision — parallel subclass, not multi-carrier refactor

### The two options

**Option A: Multi-carrier refactor of `MagentoShippingMethod`.** Drop the
hardcoded `_code = 'shuboflat'`, generalize the constructor to take a
`CarrierGatewayInterface` keyed lookup against ShippingCore's
`CarrierRegistry`, and let DI virtualTypes register one Magento-carrier
instance per ShippingCore gateway. Single class serves every adapter.

**Option B: Parallel subclass — `Shubo\ShippingShippo\Model\Carrier\ShippoMagentoCarrier extends AbstractCarrier`.**
A second concrete carrier class lives in `Shubo_ShippingShippo`, registered
with its own `<carriers><shippo>` config block, bound to a Shippo-specific
`RateQuoteService` (or directly to `ShippoCarrierGateway`). Flat-rate stays
exactly as it is.

### Recommendation: Option B (parallel subclass)

**Reasons in priority order:**

1. **Carrier-specific state plumbing.** Shippo's checkout output carries a
   `rate_object_id` that must round-trip into checkout state and survive
   payment to admin, because the admin "Ship" button needs the rate to buy
   the label (`POST /transactions` requires `rate.object_id`). Flat-rate has
   no such concept — it is a static lookup, fully self-contained per request.
   A multi-carrier refactor (Option A) would need to teach
   `MagentoShippingMethod` about carrier-specific quote-side state,
   poisoning the flat-rate path with per-carrier conditionals.
2. **Per-carrier rate-cache TTL semantics.** Flat-rate's `_isFixed = true`
   and `RATE_FALLBACK_GEL` design assumes rate stability over very long TTLs.
   Shippo rates have short TTLs (Shippo's own
   `rate.duration_terms` field can be sub-hour), so the carrier-side cache
   policy diverges. Two classes, two policies, no conditional ladder.
3. **Failure-mode isolation.** A bug in the Shippo path under Option A would
   risk taking down flat-rate at checkout. Under Option B, if
   `ShippoMagentoCarrier::collectRates()` raises, Magento drops just the
   Shippo line from the rate list — exactly what we want. Flat-rate keeps
   serving customers.
4. **DI complexity.** Option A would need a virtualType per carrier with
   distinct `_code`, `_isFixed`, plus carrier-keyed gateway injection.
   That's a more elaborate DI graph than two plain class registrations.
5. **Future carriers.** When Wolt Drive lands as `Shubo_ShippingWolt`, it
   gets its own `WoltMagentoCarrier`. Pattern is uniform across adapters
   and matches the per-adapter standalone-repo distribution model
   (`~/module-shipping-{core,shippo,wolt}/`).

Option A's only real win — code reuse on the boilerplate `buildQuoteRequest`
/ `parcelFromRequest` / `destinationFromRequest` methods — can be captured
later by extracting an `AbstractMarketplaceCarrier extends AbstractCarrier`
in `Shubo_ShippingCore` once we have ≥3 adapters and the duplication is
proven. Pre-extracting now is YAGNI.

### What we need from ShippingCore to make Option B clean

Almost nothing. The only ShippingCore touch is verifying that
`CreateShipmentOnMagentoShipment` already handles the `shippo` carrier code
through its existing `explode('_', $method, 2)` split. **It does** — see
§5 below. Zero changes required to ShippingCore for Session 6.

---

## 3. File-level breakdown

### 3.1 New files in `~/module-shipping-shippo/`

| Path | Purpose |
|---|---|
| `Model/Carrier/ShippoMagentoCarrier.php` | The parallel `AbstractCarrier` subclass. `_code = 'shippo'`, `_isFixed = false`. `collectRates()` calls `ShippoCarrierGateway::quote()` directly (no `RateQuoteService` indirection — the gateway IS the service for a single-carrier path). Builds `Magento\Quote\Model\Quote\Address\RateResult\Method` rows, one per `RateOption`, **and writes the `rate_object_id` into the Method's `method_description` or a custom additional_data field** so the post-checkout pipeline can read it back. |
| `etc/config.xml` (UPDATE — currently has only `shubo_shipping_shippo` group) | Add `<carriers><shippo><active>0</active><model>Shubo\ShippingShippo\Model\Carrier\ShippoMagentoCarrier</model><name>Shippo</name><title>Shipping (via Shippo)</title><sallowspecific>0</sallowspecific></shippo></carriers>` block. `active=0` by default; operator flips via Step 5 of go-live runbook. |
| `etc/adminhtml/system.xml` (UPDATE) | Add `<group id="checkout">` with `active`, `title`, `name`, `sallowspecific`, `specificcountry`, `showmethod` fields under `shubo_shipping_shippo`. Mirrors the `Magento\OfflineShipping` carrier groups. Surfaces the toggle in Stores → Configuration → Sales → Shipping Methods. |
| `Plugin/Quote/SaveRateObjectIdOnShippingMethodPlugin.php` | Plugin on `Magento\Quote\Model\ShippingAddressManagementInterface::assign` (or `ShippingMethodManagementInterface::set`) — when the customer picks a `shippo_*` shipping method, copy the `rate_object_id` (carried in the rate's additional data set by `ShippoMagentoCarrier::collectRates`) onto `quote.shipping_address.shipping_method_extension_attributes` so it survives the quote → order conversion. |
| `Plugin/Sales/CopyRateObjectIdToOrderPlugin.php` | Plugin on `Magento\Quote\Model\QuoteManagement::submit` (or the equivalent QuoteToOrder converter). When the order is materialized, copy the `rate_object_id` from quote shipping-address extension attributes into `sales_order.shippo_rate_object_id` (new column — see §3.5). |
| `Observer/PopulateShipmentTrackOnLabelPurchase.php` | Per the tracking-visibility audit (`docs/tracking-visibility-audit.md` §1): subscribe to `shubo_shipping_label_purchased` (verify the actual event name in ShippingCore — it may be `shubo_shipping_shipment_dispatched`). On Shippo label purchase, read `tracking_number`, `tracking_url_provider`, and `carrier` from the Shippo transaction and create a `sales_shipment_track` row with `carrier_code='shippo'`, `title='<USPS\|UPS\|...> via Shippo'`, `number=tracking_number`, `url=tracking_url_provider`. |
| `Block/Tracking/ShippoCarrierResolver.php` | Block override per tracking-visibility audit §3 — composes the customer-facing carrier title for the popup (e.g. "USPS via Shippo" instead of "Shubo Flat Rate"). |
| `view/frontend/layout/sales_order_view.xml` | Optional but cleanest UX — layout-level reference to inject the Shippo tracking-detail block when there's at least one Shippo track row on the order. |
| `Plugin/Checkout/ValidateShippingAddressPlugin.php` | Plugin on `Magento\Checkout\Api\ShippingInformationManagementInterface::saveAddressInformation` that calls `Shubo\ShippingShippo\Service\AddressValidator::validate()` on the destination address before rates are computed. On `valid=false` with `suggestion`, attaches the suggestion to the response so the storefront can show a "Did you mean?" prompt. On `valid=false` without suggestion, raises a `LocalizedException` blocking checkout. On fail-open (`valid=true` with the standard fail-open message), passes through silently — the validator's own logger has already warned. |
| `etc/di.xml` (UPDATE) | Wire the four new plugins above. |
| `etc/db_schema.xml` (UPDATE) | Add `shippo_rate_object_id VARCHAR(64) NULL` to `sales_order` AND `sales_shipment.additional_information` extension. See §3.5. |
| `etc/db_schema_whitelist.json` (UPDATE) | Whitelist the new column. |
| `Test/Integration/ShippoCheckoutIntegrationTest.php` | New integration test — drives `ShippoMagentoCarrier::collectRates()` against sandbox and asserts the rate methods carry round-trip-able `rate_object_id` data. |
| `Test/Unit/Model/Carrier/ShippoMagentoCarrierTest.php` | Unit tests for the carrier class — disabled returns false, no merchant returns false, rate exception returns false, single-rate returns Method with rate_object_id, multi-rate returns multiple Methods. |
| `Test/Unit/Plugin/Quote/SaveRateObjectIdOnShippingMethodPluginTest.php` | Plugin unit tests — happy path (shippo method, id saved), non-shippo method (no-op), missing rate_object_id (logged warning, passthrough). |
| `Test/Unit/Observer/PopulateShipmentTrackOnLabelPurchaseTest.php` | Observer unit tests — happy path, missing transaction (silent skip), idempotent (track row already exists). |
| `Test/Unit/Plugin/Checkout/ValidateShippingAddressPluginTest.php` | Plugin unit tests — valid passthrough, invalid+suggestion attached, invalid+no-suggestion raises, fail-open passthrough. |

### 3.2 Files modified in `~/module-shipping-shippo/` (already exist)

| Path | Change |
|---|---|
| `Model/Adapter/ShippoCarrierGateway.php` | Likely no change — Session 5 already exposes `quote()`, `createShipment()`, `getShipmentStatus()`. Verify the `quote()` return shape includes `rate_object_id` per `RateOption`; if not, extend `RateOption` DTO in ShippingCore (see §3.4). |
| `etc/module.xml` | Add dependency on `Magento_Quote`, `Magento_Sales`, `Magento_Checkout` if not already declared (Session 5 may have stayed framework-only). |

### 3.3 Files SESSION 6 MUST NOT change

These are the canary list — touching any of these is out of scope, file a separate ticket.

- `Shubo\ShippingCore\Model\Carrier\MagentoShippingMethod` — flat-rate carrier stays exactly as-is.
- `Shubo\ShippingCore\Model\Carrier\FlatRateGateway` — same.
- `Shubo\ShippingCore\Observer\CreateShipmentOnMagentoShipment` — already handles `shippo` (§5 below).
- `Shubo\Merchant\Observer\ResolveShippingRateContext` — already carrier-agnostic; resolves merchant from cart items regardless of which carrier asked.

### 3.4 ShippingCore extension if `RateOption` does not carry adapter metadata

Audit `Shubo\ShippingCore\Api\Data\Dto\RateOption.php` first thing in Session 6.
If it does NOT have an `adapterMetadata` (or similarly-named) `array<string, scalar>` field for carrier-specific opaque data, **add it** as an additive change in `~/module-shipping-core/`:

- New optional readonly property `?array $adapterMetadata = null` on `RateOption`.
- Update `FlatRateGateway` to pass `null` (no metadata needed).
- Update `ShippoCarrierGateway` to pass `['rate_object_id' => $rate->object_id, 'carrier_token' => $rate->provider]`.

This keeps ShippingCore carrier-agnostic — nothing in ShippingCore knows what `rate_object_id` means, it's just an opaque blob the adapter writes and the adapter's own Magento carrier reads. Per `reference_module_distribution.md`, this requires a standalone tag of `module-shipping-core` and a `composer update shubo/module-shipping-core` in duka. Track as a separate sub-task in Sub-phase 1.

### 3.5 Schema changes

**Where to persist `rate_object_id` along the lifecycle:**

| Stage | Storage | Column |
|---|---|---|
| Rate quote (carrier returns rates) | `Magento\Quote\Model\Quote\Address\RateResult\Method` (in-memory only — never persisted) | Carried in `additional_data` |
| Customer picks shipping method | `quote_shipping_rate.additional_information` (or `quote_address.extension_attributes`) | New extension attribute `shippo_rate_object_id` |
| Order placement | `sales_order` table | New nullable column `shippo_rate_object_id VARCHAR(64) NULL` |
| Admin "Ship" → label purchase | `sales_shipment` extension attribute (transient) → after label purchase, persisted to `sales_shipment_track` AND to existing `shubo_shipping_shipment` row (in ShippingCore) | Reuses `shubo_shipping_shipment.external_id` for the Shippo transaction object_id; `rate_object_id` is consumed and discarded after label purchase |

Why a column on `sales_order` (not just an extension attribute):

- The admin "Ship" workflow can fire days after order placement. The `rate.object_id` is what we need to pass to `POST /transactions` at that point. A persistent column is the only safe carrier — extension attributes in Magento are not guaranteed to round-trip through every order edit / reload.
- Shippo rates expire (their own `rate.duration_terms` is typically 24h-7d). If the operator hits "Ship" after the rate has expired, the label-purchase API returns a clear error and our admin-side handler can surface "rate expired, requote required" — but we cannot detect that without the original `object_id` to query.
- Cost: one nullable VARCHAR(64) column. Storage impact is negligible (~50 bytes/row × order volume).

Schema patch lives in `Shubo_ShippingShippo`'s `etc/db_schema.xml`. **Not** in `Shubo_ShippingCore` — `rate_object_id` is a Shippo concept, the column belongs to the adapter that uses it.

---

## 4. Checkout state plumbing — concrete data flow

This is the trickiest piece. Walk through with table/column names so the developer agent has zero ambiguity.

### 4.1 Quote phase (customer at shipping-method step)

```
Magento dispatches `Shipping\Model\Shipping::collectCarrierRates()`
  -> ShippoMagentoCarrier::collectRates(RateRequest)
     -> resolves merchant context via shubo_shipping_resolve_rate_context (existing event in ShippingCore)
     -> ShippoCarrierGateway::quote(QuoteRequest) -> array<RateOption>
     -> for each RateOption:
        $method = MethodFactory::create();
        $method->setCarrier('shippo');
        $method->setMethod($option->methodCode);            // e.g. 'shippo_usps_priority'
        $method->setMethodTitle($option->methodTitle);      // e.g. 'USPS Priority Mail (2 days)'
        $method->setPrice($priceGel);
        $method->setData('rate_object_id', $option->adapterMetadata['rate_object_id']);
        $method->setData('shippo_carrier_token', $option->adapterMetadata['carrier_token']);
        $result->append($method);
     -> return Result
```

Magento serializes the rate methods into `quote_shipping_rate` table during checkout — `additional_data` is one of the columns by default (or stored in a serialized `code` column depending on Magento version; verify in 2.4.8 schema). **Action item for developer agent in Sub-phase 1**: confirm exactly where `setData('rate_object_id', ...)` lands in `quote_shipping_rate` for Magento 2.4.8.

### 4.2 Customer picks Shippo method

`Magento\Quote\Api\ShippingMethodManagementInterface::set($cartId, 'shippo', 'shippo_usps_priority')` writes to `quote_shipping_rate.method` and updates `quote_address.shipping_method = 'shippo_shippo_usps_priority'` (carrier_method format).

**Plugin: `SaveRateObjectIdOnShippingMethodPlugin`** (afterSet on `ShippingMethodManagementInterface`):

```
afterSet:
  reload the quote
  read selected rate from quote_shipping_rate where address_id = ... and code = 'shippo_shippo_usps_priority'
  if code starts with 'shippo_':
      $rateObjectId = $rate->getData('rate_object_id');
      $quote->getShippingAddress()->setData('shippo_rate_object_id', $rateObjectId);
      $quote->save();
```

This persists the rate id at the quote level so order placement can pick it up.

### 4.3 Order placement

When the customer pays and Magento converts quote → order via `QuoteManagement::submit()`:

**Plugin: `CopyRateObjectIdToOrderPlugin`** (afterSubmit on `QuoteManagement`):

```
afterSubmit($result, $quote):
  $rateObjectId = $quote->getShippingAddress()->getData('shippo_rate_object_id');
  if ($rateObjectId !== null):
      $result->setData('shippo_rate_object_id', $rateObjectId);
      $orderRepository->save($result);
  return $result;
```

The new column `sales_order.shippo_rate_object_id` is now populated. From here, days can pass without harm — the column survives reloads, edits, and admin views.

### 4.4 Admin "Ship" → label purchase

When the operator clicks "Ship" on the admin order view, `Magento\Sales\Controller\Adminhtml\Order\Shipment\Save` creates a `sales_shipment` row and fires `sales_order_shipment_save_after`. This is intercepted by `Shubo\ShippingCore\Observer\CreateShipmentOnMagentoShipment` (already in ShippingCore, no changes).

The observer dispatches via the carrier registry. For our Shippo case:

```
CreateShipmentOnMagentoShipment::execute($observer):
  carrier_code = 'shippo' (from explode('_', order.shipping_method, 2))
  merchant_id = resolved from shubo_shipping_resolve_merchant_for_order
  ShipmentRequest built, dispatched to ShipmentOrchestrator
     -> CarrierRegistry::get('shippo') -> ShippoCarrierGateway
     -> ShippoCarrierGateway::createShipment($request)
        BUT: this method needs the rate_object_id!
        Read from $order->getData('shippo_rate_object_id') — passed in via $request->metadata
```

**Action item:** `ShipmentRequest` (in ShippingCore) already has a `metadata: array` field per `CreateShipmentOnMagentoShipment::buildRequest()`. The observer must add `'shippo_rate_object_id' => $order->getData('shippo_rate_object_id')` to that metadata array. Since CreateShipmentOnMagentoShipment is carrier-agnostic, this is a generic pattern: **any adapter-specific metadata on the order gets bundled into ShipmentRequest.metadata**. Document this pattern in the ShippingCore observer's docblock.

`ShippoCarrierGateway::createShipment()` reads `$request->metadata['shippo_rate_object_id']` and uses it for `POST /transactions`. Existing code already takes the rate id; only the source changes from "passed by the caller" to "read from request metadata".

### 4.5 Label-purchased event → tracking visibility

After label purchase, `ShippoWebhookHandler` (or the synchronous `createShipment` success path) fires the existing ShippingCore `shubo_shipping_label_purchased` event (verify name in code — may already be `shubo_shipping_shipment_dispatched`).

`PopulateShipmentTrackOnLabelPurchase` observes this event, reads the persisted `shubo_shipping_shippo_transaction` row for the just-purchased label, and writes to `sales_shipment_track`:

```
sales_shipment_track INSERT:
  parent_id = $shipment->getId()
  carrier_code = 'shippo'
  title = sprintf('%s via Shippo', strtoupper($transaction->getCarrier()))   // 'USPS via Shippo'
  number = $transaction->getTrackingNumber()
  description = $transaction->getTrackingUrlProvider()      // deep-linked tracking URL
```

(Field `description` is the Magento convention for the carrier's tracking URL — verify against `Magento\Sales\Model\Order\Shipment\Track::getNumber()` source.)

Customer sees this on `My Orders → View Order → Track Your Order` popup, with the underlying carrier (USPS / UPS / DHL Express) and a clickable deep-link.

---

## 5. Admin-side dispatch — code-walk verification

Per the original scope-decision §G: verify that
`Shubo\ShippingCore\Observer\CreateShipmentOnMagentoShipment` handles the
`shippo` carrier code through its existing split-on-underscore design.

**Code walk (file: `~/module-shipping-core/Observer/CreateShipmentOnMagentoShipment.php` lines 169-178):**

```php
private function resolveCarrierCode(SalesOrder $order): ?string
{
    $method = $order->getShippingMethod();
    if (!is_string($method) || $method === '') {
        return null;
    }
    $parts = explode('_', $method, 2);
    $code = $parts[0] ?? '';
    return $code === '' ? null : $code;
}
```

For `shipping_method = 'shippo_shippo_usps_priority'`:

- `explode('_', 'shippo_shippo_usps_priority', 2)` returns `['shippo', 'shippo_usps_priority']`.
- `$code = 'shippo'`.
- Returns `'shippo'`.

**Confirmed:** the existing observer correctly resolves `shippo` as the carrier code. No changes needed in `CreateShipmentOnMagentoShipment`.

The downstream `CarrierRegistry::get('shippo')` resolution works because Session 5's `etc/di.xml` already registers the gateway (line 22-28):

```xml
<type name="Shubo\ShippingCore\Model\Carrier\CarrierRegistry">
    <arguments>
        <argument name="gateways" xsi:type="array">
            <item name="shippo" xsi:type="object">Shubo\ShippingShippo\Model\Adapter\ShippoCarrierGateway</item>
        </argument>
    </arguments>
</type>
```

So the entire admin-shipment dispatch chain is **already wired end-to-end**.
The only Session 6 change to this chain is the metadata enrichment described in §4.4 — the observer must include `shippo_rate_object_id` in the dispatched `ShipmentRequest.metadata`.

---

## 6. Test coverage required

### 6.1 Unit tests (in standalone repo)

Per the file-level breakdown §3.1:

- `ShippoMagentoCarrierTest` — disabled, no-merchant, exception, single-rate, multi-rate (5 tests).
- `SaveRateObjectIdOnShippingMethodPluginTest` — happy path, non-shippo, missing-metadata (3 tests).
- `CopyRateObjectIdToOrderPluginTest` — happy path, no rate id on quote (2 tests).
- `PopulateShipmentTrackOnLabelPurchaseTest` — happy path, missing transaction, idempotent (3 tests).
- `ValidateShippingAddressPluginTest` — valid passthrough, invalid+suggestion, invalid+no-suggestion blocks, fail-open passthrough (4 tests).

Total: **17 new unit tests minimum.** All must mock dependencies — no Magento bootstrap.

### 6.2 Integration tests (in standalone repo)

- `ShippoCheckoutIntegrationTest` — drives `ShippoMagentoCarrier::collectRates()` against sandbox; asserts `rate_object_id` is in the returned `Method` rows. Skipped if `~/.shippo-key` absent (matches Session 5 pattern).

### 6.3 Playwright specs (in duka — `tests/e2e/`)

These are the deferred items from Session 5 §4 — Session 6 unblocks them.

| Spec | Mirrors | Asserts |
|---|---|---|
| `tests/e2e/shippo-checkout-lifecycle.spec.ts` | The PHP `ShippoLifecycleTest` from Session 5 Phase A — but at the storefront layer | Add to cart → checkout → US shipping address → Shippo rate appears → pick cheapest → place sandbox order → admin "Ship" → label purchased → tracking number visible on customer order view |
| `tests/e2e/shippo-admin-ship.spec.ts` | Admin-side ship → label-purchased flow | Admin login → open the order from above → "Ship" button → label PDF downloadable → `sales_shipment_track` row created with `carrier_code='shippo'` and non-empty `url` (deep-link) |
| `tests/e2e/shippo-multi-merchant.spec.ts` | The PHP `ShippoMultiMerchantTest` from Session 5 Phase C — but full-stack | Two carts on two merchants → two checkouts → two Shippo orders → two admin shipments → **assert `shubo_payout_ledger_entry` has two distinct `SHIPPING_FEE_DEBT` entries, one per merchant** (this is the per-merchant ledger correctness assertion the developer agent had to defer in Phase C) |

All Playwright specs run with `headless: false` per `feedback_testing.md`. Edge cases: empty cart, abandoned checkout mid-rate-fetch, rate expired before admin Ship, address validation failure with suggestion shown to customer.

### 6.4 Quality gates

PHPStan level 8 + PHPCS Magento2 + 17+ unit tests + 1 integration test + 3
Playwright specs all green before reviewer sign-off.

---

## 7. Sub-phases and estimated work

### Sub-phase 1 — Carrier subclass + config + DI (1.5-2 hours)

- [ ] Audit `RateOption` DTO in ShippingCore for adapter-metadata field; add if missing (separate ShippingCore tag + composer update if needed).
- [ ] Update `ShippoCarrierGateway::quote()` to populate `adapterMetadata` on each `RateOption`.
- [ ] Write `Model/Carrier/ShippoMagentoCarrier`.
- [ ] Add `<carriers><shippo>` to `etc/config.xml`.
- [ ] Add `<group id="checkout">` to `etc/adminhtml/system.xml`.
- [ ] Module dep updates in `etc/module.xml` if needed.
- [ ] Write unit tests for `ShippoMagentoCarrier` (5 tests).
- [ ] Smoke: `bin/magento cache:flush` then add product to cart, see Shippo rates appear at checkout.

### Sub-phase 2 — State plumbing through to order (1.5-2 hours)

- [ ] Write `Plugin/Quote/SaveRateObjectIdOnShippingMethodPlugin`.
- [ ] Write `Plugin/Sales/CopyRateObjectIdToOrderPlugin`.
- [ ] Add `shippo_rate_object_id` column to `etc/db_schema.xml` + whitelist.
- [ ] Update `CreateShipmentOnMagentoShipment` (in ShippingCore, separate tag) to include `shippo_rate_object_id` in `ShipmentRequest.metadata` when present on the order.
- [ ] Update `ShippoCarrierGateway::createShipment()` to read from `request.metadata['shippo_rate_object_id']`.
- [ ] Write unit tests for both plugins (5 tests).
- [ ] Smoke: place a sandbox order, verify `sales_order.shippo_rate_object_id` populated.

### Sub-phase 3 — Tracking visibility + address validator wiring (1.5-2 hours)

- [ ] Write `Observer/PopulateShipmentTrackOnLabelPurchase` per tracking-visibility audit §1.
- [ ] Write `Block/Tracking/ShippoCarrierResolver` per audit §3.
- [ ] Write `view/frontend/layout/sales_order_view.xml` per audit §4.
- [ ] Write `Plugin/Checkout/ValidateShippingAddressPlugin` calling the existing `Service/AddressValidator`.
- [ ] Wire all four in `etc/di.xml`.
- [ ] Write unit tests (7 tests across observer + plugin).
- [ ] Smoke: ship a sandbox order, verify `sales_shipment_track` row written with correct carrier title and deep-link URL.

### Sub-phase 4 — Playwright + integration test + quality gates (1.5-2 hours)

- [ ] Write `Test/Integration/ShippoCheckoutIntegrationTest`.
- [ ] Write `tests/e2e/shippo-checkout-lifecycle.spec.ts`.
- [ ] Write `tests/e2e/shippo-admin-ship.spec.ts`.
- [ ] Write `tests/e2e/shippo-multi-merchant.spec.ts` with the per-merchant ledger assertion.
- [ ] Run all quality gates: PHPStan level 8, PHPCS Magento2, full Shubo unit suite, all Playwright specs.
- [ ] Tag standalone repo, `composer update shubo/module-shipping-shippo` in duka, commit `composer.lock`.

**Total: 6-8 hours of focused work, plus 1-2 hours of debugging buffer = 6-10 hours wall time.**

---

## 8. Pre-requisites for Session 6

These are the Session 5 deliverables that must be merge-clean before Session 6
starts. As of 2026-04-25 (developer agent's HEAD `84858e4`):

- [x] `composer.lock` pinned in duka to a Session 5 standalone tag — TBD by Session 5 PR
- [x] `Service/AddressValidator.php` shipped + 5 unit tests + integration smoke — confirmed in Phase B (note: 5 tests, not the 4 originally planned — developer agent added a `testShippoFourHundredReturnsInvalidWithMessage` 4xx-rejection case that is correct and welcome)
- [x] `Console/Command/SmokeValidateAddressCommand.php` shipped + DI binding — confirmed
- [x] `Test/Integration/ShippoLifecycleTest.php` (Phase A) — confirmed
- [x] `Test/Integration/ShippoMultiMerchantTest.php` (Phase C) — confirmed
- [x] `Test/Integration/SampleDataSeedTest.php` (Phase D) — confirmed
- [x] `docs/end-to-end-trace.md`, `docs/multi-merchant-trace.md`, `docs/sample-data-trace.md`, `docs/tracking-visibility-audit.md`, `docs/address-validator-justification.md` — all confirmed present
- [x] All Phase A-E quality gates green

**Session 6 starts with merging this Session 5 PR first.** Do not branch from
the pre-Session-5 main — every test in the Session 5 suite is a regression
guard for Session 6's changes.

---

## 9. Risks and mitigations

| Risk | Mitigation |
|---|---|
| `RateOption` DTO doesn't carry adapter metadata, requiring ShippingCore extension | Sub-phase 1 first action is the audit; if missing, file as additive change tagged + composer-updated before the rest of Session 6 |
| Magento 2.4.8 `quote_shipping_rate` schema doesn't preserve `setData('rate_object_id', ...)` round-trip | Fall back to using `quote_address.extension_attributes` instead — both are documented Magento patterns; pick whichever round-trips cleanly in the smoke test |
| `shubo_shipping_label_purchased` event name doesn't exist in ShippingCore (audit referenced it speculatively) | Sub-phase 3 first action is grep for the event name; rename observer subscription to whatever the actual event is (likely `shubo_shipping_shipment_dispatched` based on ShippingCore conventions) |
| Per-merchant ledger assertion in `tests/e2e/shippo-multi-merchant.spec.ts` reveals a real bug in the Payout chain when triggered through Shippo (vs flat-rate which is the only carrier that has exercised this chain in prod) | Block Session 6 sign-off until the bug is filed in `KNOWN_ISSUES.md` and either fixed in Session 6 scope or explicitly deferred with prerequisite |
| `rate_object_id` survives in `sales_order` but the underlying Shippo rate has expired by the time admin clicks "Ship" | Surface "rate expired, please requote" error in admin UI; add a soft re-quote button as a follow-up (out of Session 6 scope, file as KNOWN_ISSUES) |

---

## 10. Architect sign-off

Design is complete. Parallel-subclass route is justified on five grounds
(state plumbing, cache TTL, failure isolation, DI complexity, future carriers).
File-level breakdown covers every new and modified file. Checkout state
plumbing has a concrete table/column data flow. Admin-side dispatch is
verified by code-walk against ShippingCore's actual code. Test coverage is
17 unit + 1 integration + 3 Playwright minimum. Sub-phased into four ~2-hour
chunks for execution discipline.

If reviewer or developer agent disagrees with the parallel-subclass decision
during implementation, escalate to architect (myself) — do not unilaterally
flip to Option A. The five-point rationale would need to be argued down item
by item.

— Architect, 2026-04-25
