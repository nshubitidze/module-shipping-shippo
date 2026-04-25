# Customer Tracking Visibility Audit (Phase E)

Read-only audit of where Shippo tracking *would* surface to customers in Magento today, what is missing, and exactly which files Session 6 must override or extend to make Shippo tracking first-class on the customer-facing storefront.

Scope: this audit lists files. It does NOT change them. Implementation is queued for Session 6 alongside the checkout integration (per architect scope decision §4) — there is no value rendering Shippo tracking on the customer order page until Shippo can produce a real `sales_shipment_track` row, and Shippo cannot do that until checkout selects the Shippo carrier.

## Current state

### How tracking lands in Magento today (legacy flat-rate path)

```
Admin "Ship" button on sales_order
   -> creates sales_shipment row
       + sales_shipment_track row (carrier_code='shuboflat', title='Shubo Flat Rate', number=…)
   -> sales_order_shipment_save_after fires
       -> Shubo\ShippingCore\Observer\CreateShipmentOnMagentoShipment
           -> reads order.shipping_method, splits on '_', extracts carrier_code='shuboflat'
           -> dispatches ShipmentOrchestrator -> FlatRateGateway::createShipment
```

Customer sees the result via Magento's stock template stack:
- `vendor/magento/module-sales/view/frontend/templates/order/view.phtml` lines 24-26 -> `getChildHtml('tracking-info-link')`
- `vendor/magento/module-shipping/view/frontend/templates/tracking/popup.phtml` -> opens a popup
- `vendor/magento/module-shipping/view/frontend/templates/tracking/details.phtml` -> renders the `<table>` with Tracking Number / Carrier / Status / Track URL fields

Because the row in `sales_shipment_track` has `title='Shubo Flat Rate'` and no deep-link URL, the customer popup currently shows:
- Tracking Number: `9XX...`
- Carrier: `Shubo Flat Rate` (NOT "USPS via Shippo" or similar)
- No clickable tracking URL (no `track.url` field set)

### What is missing for Shippo specifically

1. **Shippo never reaches `sales_shipment_track`.** No code path today writes a `track` row for the Shippo carrier. The `CreateShipmentOnMagentoShipment` observer would dispatch the orchestrator if `order.shipping_method` started with `shippo_`, but checkout never sets that — `MagentoShippingMethod._code = 'shuboflat'` is hardcoded in core.

2. **Carrier title would be wrong even if it landed.** The legacy flat-rate path writes `title='Shubo Flat Rate'`. For Shippo, we want the actual underlying carrier (USPS, UPS, DHL Express) plus a "via Shippo" qualifier so the customer knows which courier physically holds their package.

3. **Tracking URL is not deep-linked.** Shippo returns a `tracking_url_provider` field on the transaction (e.g. `https://tools.usps.com/go/TrackConfirmAction_input?origTrackNum=9XX...`). The legacy flat-rate path has no URL to write, so `sales_shipment_track.url` is empty everywhere. For Shippo, that field IS available and should be written on label purchase so the customer popup renders a clickable "Track:" link.

4. **No real-time status synchronization to the customer popup.** When Shippo's webhook updates `shubo_shipping_shipment.status` from PRE_TRANSIT -> TRANSIT -> DELIVERED, the customer popup keeps showing whatever Magento's tracking-info HTTP fetch returns from the carrier — which for Shippo means re-querying Shippo every time the popup opens. That's a Shippo-side rate-limit risk and has no UX caching. A Phase 2 wireup would surface our DB-cached status to the popup and only fall back to live-fetch when the cache is older than N minutes.

## Files Session 6 must touch (>=3, listed)

Session 6's checkout integration design doc (`docs/session-6-checkout-integration-design.md`) covers the full file list; this audit focuses specifically on what's needed for tracking visibility once shipments exist.

### REQUIRED for Session 6 (tracking visibility prerequisites)

1. **`/home/nika/module-shipping-shippo/Observer/PopulateShipmentTrackOnLabelPurchase.php`** (NEW)
   Subscribe to `shubo_shipping_label_purchased` (or its equivalent in ShippingCore — verify in Session 6 design). On Shippo label purchase, read the Shippo transaction's `tracking_number`, `tracking_url_provider`, and `carrier` (provider) and create a `sales_shipment_track` row with:
   ```
   carrier_code = 'shippo'
   title = 'USPS via Shippo' / 'UPS via Shippo' / etc. (composed from rate.provider)
   number = transaction.tracking_number
   url = transaction.tracking_url_provider  // <-- the missing deep-link
   ```

2. **`/home/nika/module-shipping-shippo/etc/config.xml`** (UPDATE — add `<carriers><shippo>` block)
   Required so Magento recognizes `shippo` as a valid carrier code on the `sales_shipment_track.carrier_code` field. Without this, Magento's tracking link block falls back to "Custom Value" rendering and ignores the `url` field. Currently the file does not exist (Phase 1 module was config.xml-free per design doc §4).

3. **`/home/nika/module-shipping-shippo/Block/Tracking/ShippoCarrierResolver.php`** (NEW) + corresponding template override
   When `Magento\Shipping\Block\Tracking\Link::__construct` runs for our `shippo` carrier code, it currently treats us as a generic carrier and uses the `title` field from `sales_shipment_track` verbatim. To make the customer popup show Shippo's underlying carrier (USPS / UPS / DHL Express) plus the "via Shippo" qualifier, we need either:
   (a) a layout XML override at `view/frontend/layout/sales_order_view.xml` that swaps the block class, OR
   (b) a plugin on `Magento\Shipping\Helper\Data::getTrackingPopupUrlBySalesModel` that intercepts the URL composition for `carrier_code='shippo'`.

4. **`/home/nika/module-shipping-shippo/view/frontend/layout/sales_order_view.xml`** (NEW)
   Optional: layout-level reference to inject our custom tracking-detail block above Magento's stock one when there's at least one Shippo track row. Cleanest UX is a single unified table; this layout file enables that.

### OPTIONAL — Phase 2 UX polish (post-Session 6)

5. **`/home/nika/module-shipping-shippo/Plugin/CacheTrackingStatusPlugin.php`** (NEW)
   Plugin on `Magento\Shipping\Block\Tracking\Popup::getTrackingInfo` that prefers our `shubo_shipping_shipment.status` over a live Shippo fetch when the local row is < N minutes old. Saves Shippo API calls and gives the customer a faster popup load.

6. **`/home/nika/module-shipping-core/Block/CustomerStatusBadge.php`** (potential new block in Core)
   A small block that renders the normalized status (in_transit / delivered / returned / failed) as a colored badge, reusable across all carriers. Belongs in Core because it's carrier-agnostic. Optional for Session 6.

## What this audit does NOT cover

- **Email rendering (order shipment confirmation email).** Magento's stock email template at `vendor/magento/module-sales/view/frontend/email/shipment_new.html` already includes tracking info if `sales_shipment_track` is populated. No Shippo-specific override needed for the email path; it inherits whatever the popup shows.
- **PWA / GraphQL.** When the storefront moves off Luma onto Hyva or ScandiPWA (architecture-locked decision), the tracking info will be served via GraphQL via a `customer.orders[].shipments[].tracking[]` query. Session 6 should add a resolver that exposes the Shippo deep-link URL alongside the tracking number.
- **Admin order grid.** Magento's admin `sales_order` grid shows a tracking column derived from `sales_shipment_track`; once populated by Session 6's observer, it works without further work.

## Summary

| Concern | Today | Session 6 fixes by |
|---|---|---|
| Shippo tracking row written to `sales_shipment_track` | NO (no Shippo path through CreateShipmentOnMagentoShipment) | New `Observer/PopulateShipmentTrackOnLabelPurchase` |
| Carrier title shows underlying courier | NO ("Shubo Flat Rate" hardcoded) | Composed from rate.provider in the new observer |
| Tracking URL is deep-linked | NO (URL field empty everywhere) | Read from `transaction.tracking_url_provider` and write to `sales_shipment_track.url` |
| Magento recognizes `shippo` as a carrier | NO (no `<carriers><shippo>` config block) | New `etc/config.xml` Phase B (Session 6) |

>=3 specific files Session 6 must touch — confirmed.
