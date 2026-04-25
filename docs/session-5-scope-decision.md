# Session 5 — Scope Decision (Architect Sign-off)

**Author:** Architect
**Date:** 2026-04-25
**Session prompt:** `~/Desktop/dukaplans/2026-04-25/session-5-shippo-end-to-end.md`
**Module under change:** `Shubo_ShippingShippo` (standalone canonical: `~/module-shipping-shippo/`, vendored in duka via `composer.lock`)
**Companion module touched (read-only):** `Shubo_ShippingCore` (standalone: `~/module-shipping-core/`)

---

## 1. Decision — Path B (adapter-level proof + sample data + runbook + gap docs)

We take **Path B** as written in the override brief.

**Justification (4 sentences):** The session prompt presupposes a checkout → Shippo rates wiring that does not exist — `Shubo\ShippingCore\Model\Carrier\MagentoShippingMethod` is hard-pinned to `_code = 'shuboflat'` and to `RateQuoteServiceInterface` (which currently resolves to `FlatRateGateway`), so neither a `<carriers><shippo>` block nor a parallel subclass is enough on its own; making Shippo selectable at checkout requires either a multi-carrier refactor of `MagentoShippingMethod` or a parallel `ShippoMagentoCarrier` plus a second `RateQuoteService` binding routed to `ShippoCarrierGateway`, *plus* a per-carrier merchant-context resolver, *plus* admin-side shipment carrier dispatch (`CreateShipmentOnMagentoShipment` observer needs to grow Shippo branches). That is a 2-3 day architectural change, not a 2-3 hour patch — and rushing it tonight risks an irreversible bad design across two modules at the same time we are trying to validate the adapter under live sandbox traffic. Path B delivers everything that is *adapter-true* (lifecycle proof, multi-merchant proof, address validation, sample data, runbook, MCP-verified provenance) without faking integration we don't have. Path A is queued for Session 6 with its own design doc, where it gets the architectural attention it deserves.

**What this decision costs us:** the Playwright storefront-checkout spec, the admin "Ship" → Shippo carrier preselection, and the customer order-view tracking visibility cannot ship tonight. They are deferred to Session 6 with explicit blocking dependencies enumerated in §4.

**What this decision buys us:** a fully MCP-verified end-to-end proof of the Shippo adapter (rate → label → webhook → tracking persisted) running through the *exact same* PHP code paths that the eventual checkout integration will trigger; a working address validator service ready to wire in; a multi-merchant adapter test that proves per-merchant scoping; 5 traceable sandbox transactions with persistent `object_id`s; a precise go-live runbook; and a design doc for Session 6 that captures the missing carrier integration with full file-level breakdown. Net: real progress on testability and operability of the module we already have, and a clean handoff for the integration session.

---

## 2. Step-by-step plan (4-6 hours total)

Time estimates are wall-clock for a single developer agent working in serial; some phases parallelize and the plan calls that out.

### Phase A — PHP integration test against Shippo sandbox (90 min)

**Owner:** developer agent
**Files (new):**
- `~/module-shipping-shippo/Test/Integration/ShippoLifecycleTest.php` (extends `Magento\TestFramework\TestCase\AbstractController` or simpler `PHPUnit\Framework\TestCase` if no Magento bootstrapping is needed — see decision note below)
- `~/module-shipping-shippo/Test/Integration/_files/sample_quote_request.php` (fixture builder for `QuoteRequest` DTO — Tbilisi origin, US destination since sandbox carriers are UPS/USPS test endpoints)
- `~/module-shipping-shippo/Test/Integration/Helper/ShippoSandboxClient.php` (thin wrapper that reads `~/.shippo-key`; one place to skip the test cleanly if the key is absent, so CI without a key doesn't red-fail)

**What it does:**
1. Calls `ShippoCarrierGateway::quote()` with a fixture `QuoteRequest` → assert ≥1 `RateOption` returned, capture `rate_object_id` from a UPS or USPS test rate.
2. Calls `ShippoCarrierGateway::createShipment()` with that rate → assert `LabelResponse` returned with non-empty `label_url`, `tracking_number`, `transaction_object_id`.
3. HTTP HEAD on `label_url` → assert 200 + `Content-Type: application/pdf` (or 302 redirect chain that terminates in a PDF; Shippo CDN sometimes redirects).
4. Calls `ShippoCarrierGateway::status()` (or whatever the gateway's lookup method is — verify against `Shubo\ShippingCore\Api\CarrierGatewayInterface`) → assert response shape.
5. Constructs a fake `track_updated` Shippo webhook body for the persisted `tracking_number`, signs it with the registered webhook secret, hands it to `ShippoWebhookHandler::handle()` (or invokes `WebhookDispatcher` with `code='shippo'`) → assert handler returns success and the persisted tracking row in `shubo_shipping_shippo_transaction` (or whichever ShippingCore table the handler writes to) is updated.
6. **Bidirectional verification at every Shippo write:** call `mcp__shippo-mcp__transactions-get` with the persisted `transaction_object_id` → diff against duka's persisted record (object_id, label_url, eta, carrier, tracking_number); fail loudly on any mismatch.

**Decision note on test base class:** If `ShippoCarrierGateway` can be constructed manually (only needs an HTTP client + config + logger), use `PHPUnit\Framework\TestCase` and avoid Magento bootstrap — runs in seconds, no DB, no fixtures. If it requires `ScopeConfigInterface` to read the API key, use Magento integration test base. Developer agent decides after reading the gateway constructor.

**Exit criteria:**
- Test passes against live Shippo sandbox in <60s wall time.
- Every external Shippo call has a corresponding MCP read-back assertion.
- Test is skipped (not failed) when `~/.shippo-key` is absent.
- `make stan` clean on the new test file.

### Phase B — Address validator service + unit tests + smoke CLI (60 min)

**Owner:** developer agent
**Files (new):**
- `~/module-shipping-shippo/Service/AddressValidator.php` — wraps Shippo `POST /addresses/validate`. Returns a strict `AddressValidationResult` DTO (valid: bool, suggestion: ?ContactAddress, messages: array<string>).
- `~/module-shipping-shippo/Api/Data/Dto/AddressValidationResult.php` — DTO (constructor-promoted readonly properties, no setters, follows the existing DTO style in `Shubo\ShippingCore\Api\Data\Dto\*`).
- `~/module-shipping-shippo/Test/Unit/Service/AddressValidatorTest.php` — 4 tests:
  - valid address → result.valid = true, suggestion = null
  - invalid + suggestion → result.valid = false, suggestion populated
  - invalid + no suggestion → result.valid = false, suggestion = null, message present
  - Shippo 5xx / network exception → result.valid = true (fail-open) with a logged warning; tested via mocked HTTP client throwing
- `~/module-shipping-shippo/Console/Command/SmokeValidateAddressCommand.php` — `bin/magento shipping_shippo:smoke-validate-address` CLI that takes args (street/city/country/postcode) and prints the result. Mirrors `SmokeRateCommand`'s pattern (CommandListInterface binding via di.xml).

**Justification doc REQUIRED:** Per CLAUDE.md simplicity-first rule, a new service abstraction needs `~/module-shipping-shippo/docs/address-validator-justification.md` before the PHP lands. Dev agent writes it as: (1) Delete — no, address validation is a real business need for the eventual checkout integration; (2) Reuse — no other Shubo module hits Shippo; ShippingCore has no validator abstraction and shouldn't (carrier-specific concern); (3) Inline — no, it's invoked from at least 3 places when integration lands (rate-quote pre-flight, admin shipment creation, GraphQL `validateShippingAddress` mutation). New abstraction approved.

**Exit criteria:**
- 4 unit tests pass; PHPStan level 8 clean on the new files.
- Smoke CLI returns a valid result against a known-good US address (e.g., `1600 Pennsylvania Ave NW, Washington, DC 20500, US`) AND a deliberately-wrong address.
- Justification doc committed.
- **No checkout wiring yet** — that's Session 6.

### Phase C — Multi-merchant adapter-level test (45 min)

**Owner:** developer agent
**Files (new):**
- `~/module-shipping-shippo/Test/Integration/ShippoMultiMerchantTest.php`

**What it does:**
1. Builds two `QuoteRequest` DTOs with different `merchantId` values (1 and 4 — Tikha is id=4 per `reference_showcase_merchant.md`) and different origin addresses.
2. Calls `ShippoCarrierGateway::quote()` twice in sequence, then `createShipment()` twice → captures 2 separate transaction object_ids.
3. **Assertion that matters:** the 2 transactions are independent on Shippo's side. Verifies via `mcp__shippo-mcp__transactions-get` for each object_id — they must have distinct `address_from`, distinct `tracking_number`s, and neither should leak data from the other.
4. **What it does NOT assert:** per-merchant ledger entries in `shubo_payout_ledger_entry`. That assertion requires a Magento order chain (commission → split → ledger), which requires checkout integration. Documented in §4 as deferred.

**Exit criteria:**
- Test passes against sandbox; 2 distinct Shippo transactions created and MCP-verified.
- Cleanup step removes both transactions from the sandbox account at the end (so the sandbox doesn't accumulate noise across runs).

### Phase D — Sample data seed: 5 sandbox transactions in different lifecycle states (60 min)

**Owner:** developer agent
**Where it runs:** local docker against Shippo sandbox. **NOT prod** — the session prompt asked for prod sample data on Magento orders, but we cannot create Magento orders that touch Shippo today (no checkout wiring). Seeding raw Shippo transactions on the sandbox account is the honest equivalent and is sufficient for "future operators see real Shippo activity."

See §5 for the exact seed plan.

**Exit criteria:**
- 5 Shippo sandbox transactions exist, each in a distinct lifecycle state (or as close to distinct as Shippo's sandbox allows — see §5 caveats).
- Each transaction's `object_id`, current status, carrier, tracking number, and label URL are written to `~/module-shipping-shippo/docs/sample-data-trace.md`.
- Each is MCP-verified.

### Phase E — Customer tracking page audit (15 min, mostly a doc deliverable)

**Owner:** developer agent
**File (new):** `~/module-shipping-shippo/docs/tracking-visibility-audit.md`

**What it does:** read-only audit of:
- `app/code/Shubo/ShippingCore/view/frontend/templates/` (if any)
- Magento's default `vendor/magento/module-sales/view/frontend/templates/order/items/renderer/default.phtml` and `vendor/magento/module-shipping/view/frontend/templates/tracking/popup.phtml`
- `sales_shipment_track` table writes from the existing flat-rate flow (does anything populate it today?)

Documents:
- Where tracking number *would* appear if a Shippo shipment existed in `sales_shipment_track`.
- What is missing for Shippo tracking specifically (carrier title is "Shubo Shipping" not "Shippo / UPS"; tracking URL not deep-linked to Shippo's tracking page).
- The exact templates / blocks Session 6 needs to override.

**Exit criteria:** doc exists, lists ≥3 specific files Session 6 must touch.

### Phase F — Go-live runbook (45 min)

**Owner:** architect agent (myself, after Phases A-E land)
**File (new):** `/home/nika/duka/docs/runbooks/shippo-go-live.md`

See §6 for outline.

**Exit criteria:**
- Runbook is ordered, every step has a verification command, every step has a rollback command.
- Includes pricing reality check ($0.05/label pay-as-you-go OR $10/mo Pro), webhook re-registration for live URL, two-mode coexistence risk explained.
- Approved by reviewer agent.

### Phase G — Session 6 design doc + KNOWN_ISSUES update (30 min)

**Owner:** architect agent
**Files (new):**
- `~/module-shipping-shippo/docs/session-6-checkout-integration-design.md` — full design for the Magento carrier subclass + checkout wiring + admin shipment dispatch. Covers:
  - Decision: refactor `MagentoShippingMethod` to multi-carrier vs. add a parallel `ShippoMagentoCarrier` subclass. Recommendation: **parallel subclass** (`Shubo\ShippingShippo\Model\Carrier\ShippoMagentoCarrier extends AbstractCarrier`) bound to a new Shippo-specific `RateQuoteService` instance via DI virtualType; rationale is that ShippingCore stays carrier-agnostic and Shippo doesn't poison the flat-rate path with carrier-specific assumptions (e.g., rate caching TTLs, `rate_object_id` round-tripping into checkout state which flat-rate does not need).
  - New `<carriers><shippo>` block in `~/module-shipping-shippo/etc/config.xml`.
  - Event observer to resolve merchant context — reuse `EVENT_RESOLVE_RATE_CONTEXT` from ShippingCore (it's carrier-agnostic by design).
  - Admin shipment dispatch: `Shubo\ShippingCore\Observer\CreateShipmentOnMagentoShipment` already splits on `_` to extract carrier code; `shippo` (no underscore) flows through the existing dispatch — verify this end-to-end.
  - Checkout state: `rate_object_id` must round-trip from rate response → checkout form state → `quote_payment_information` → `sales_shipment` so label purchase has the rate to buy. This is the trickiest bit; design doc covers it.
  - Estimated work: 6-10 hours including tests.
- `/home/nika/duka/KNOWN_ISSUES.md` — append:

  ```
  - [ ] Shipping/Shippo: not selectable at checkout. `MagentoShippingMethod` hardcoded to `shuboflat`; no `<carriers><shippo>` config block; no `ShippoMagentoCarrier` subclass. Adapter layer is fully built and MCP-verified (Session 5, 2026-04-25). Integration design at `~/module-shipping-shippo/docs/session-6-checkout-integration-design.md`. Blocks: customer-facing Shippo selection, admin Shippo shipment dispatch, customer tracking visibility for Shippo shipments, end-to-end Playwright lifecycle.
  ```

**Exit criteria:** design doc reviewed by architect (self-sign-off acceptable for design docs; reviewer agent reviews implementation doc, not design); KNOWN_ISSUES entry committed.

### Order of execution

A → B → C and D in parallel (different developer-agent sub-tasks if there's bandwidth; otherwise C then D) → E → F → G.

A is the foundation — until the lifecycle test is green, B/C/D are speculative.

---

## 3. Bidirectional verification matrix

Per `~/Desktop/dukaplans/2026-04-24/AFK-VERIFICATION-CONTRACT.md`, every external write is read back via the Shippo MCP server (independent path from our HTTP client). Mismatch fails the test loudly.

| Phase | External write | MCP read-back tool | Assertions |
|---|---|---|---|
| A.1 | `POST /shipments` (rate quote) | `mcp__shippo-mcp__shipments-get` with the returned `shipment_object_id` | rate count ≥ 1; address_from / address_to round-trip exactly; carrier list matches what the gateway returned to PHP |
| A.2 | `POST /transactions` (label purchase) | `mcp__shippo-mcp__transactions-get` with the returned `transaction_object_id` | object_id matches; `label_url` matches; `tracking_number` matches; `rate.object_id` matches the rate we picked; `status = SUCCESS` |
| A.3 | `GET label_url` (PDF fetch) | n/a — direct HTTP HEAD assertion only | HTTP 200; `Content-Type: application/pdf` (allow `application/octet-stream` as fallback per Shippo docs) |
| A.5 | (no write — webhook simulation is inbound) | `mcp__shippo-mcp__tracks-get` with the `tracking_number` to verify Shippo's view of the same shipment | tracking carrier matches; status object exists |
| B (smoke CLI) | `POST /addresses/validate` | `mcp__shippo-mcp__addresses-get` with the returned `address_object_id` (validate creates an Address record on Shippo's side) | address fields round-trip; `validation_results.is_valid` matches what we returned to the caller |
| C (multi-merchant) | 2 × `POST /shipments` + 2 × `POST /transactions` | `mcp__shippo-mcp__shipments-get` and `mcp__shippo-mcp__transactions-get` for each | 2 distinct shipment object_ids; 2 distinct transaction object_ids; address_from differs between the two; no field bleed |
| D (5-state seed) | 5 × `POST /shipments` + ≥3 × `POST /transactions` + simulated tracking events | `mcp__shippo-mcp__transactions-get` and `mcp__shippo-mcp__tracks-get` for each | each transaction's object_id and current tracking status matches what we recorded in `sample-data-trace.md` |

**Failure protocol:** if any read-back diverges from the persisted value, the developer agent must STOP, dump both sides into a diff in the trace doc, and either (a) fix the gateway code if duka is wrong, or (b) escalate to architect if Shippo's API behaved unexpectedly. No "good enough" reconciliation.

**MCP availability check:** Phase A's first action is `claude mcp list | grep shippo-mcp` to confirm the server is connected. If it's not, the entire session FAILS at Phase A — no sense running tests we can't independently verify.

---

## 4. What's intentionally deferred and why

These are session-prompt items NOT being delivered tonight, with the prerequisite blocking each. This becomes Session 6's starting line.

| Session-prompt item | Why deferred | Prerequisite |
|---|---|---|
| Storefront → checkout → Shippo rates Playwright spec (prompt §1, steps a-e) | Shippo is invisible at checkout; `MagentoShippingMethod._code = 'shuboflat'` is hardcoded; no `<carriers><shippo>` config block exists | `Shubo\ShippingShippo\Model\Carrier\ShippoMagentoCarrier` + `<carriers><shippo>` in config.xml + DI binding for a Shippo-specific `RateQuoteService` |
| Admin "Ship" → Shippo carrier preselection (prompt §1, step g) | Admin shipment carrier resolution flows from `sales_shipment.shipping_method`, which today can only be `shuboflat_*`; no Shippo path exists | Same as above + verify `Shubo\ShippingCore\Observer\CreateShipmentOnMagentoShipment` handles `shippo` carrier code (it should — the split-on-`_` design suggests yes, but needs end-to-end test) |
| Label purchase from admin shipment (prompt §1, step h) | Triggered by the create-shipment observer above | Same as above |
| Customer order-page tracking visibility (prompt §1, step k + §4) | Cannot test without a real Shippo shipment record on a Magento order; doc audit (Phase E) lists the templates Session 6 must override | Checkout integration delivering a real Shippo shipment to `sales_shipment` table |
| Multi-merchant per-merchant ledger correctness assertion (prompt §2, step f) | `shubo_payout_ledger_entry` SHIPPING_FEE_DEBT entries are written by the chain `Magento_Shipment → Shubo_ShippingCore observer → Shubo_Payout`; no Shippo shipment ever reaches `Magento_Shipment` today | Checkout integration + admin shipment dispatch |
| Address validation wired at checkout (prompt §3) | The `AddressValidator` service is built in Phase B; the wiring (plugin/observer at checkout) is held back because checkout doesn't call rate-quote against Shippo today | Checkout integration |
| Sample orders on prod (prompt §5) | Cannot place Magento orders that select Shippo at checkout; we substitute with raw sandbox Shippo transactions seeded from local | Checkout integration; also a `is_demo_data` field on order's additional_information for cleanup safety |
| Reviewer-signoff doc against full session-prompt deliverable list (prompt §7) | Reviewer signs off against THIS doc's deliverables (Phases A-G), not the session prompt's. Reviewer should explicitly note "session scope reduced per architect doc 2026-04-25" | n/a — process clarification, not a code blocker |

**Session 6 starting line:** open `~/module-shipping-shippo/docs/session-6-checkout-integration-design.md`, read it, implement it. Estimated 6-10 hours; the design doc covers every file to touch. After Session 6 lands, the deferred items above unblock and Session 7 (or a re-run of Session 5's prompt) delivers them in their original Playwright form.

---

## 5. Sample-data seeding plan (Phase D)

Goal: 5 Shippo sandbox transactions, each in a distinct lifecycle state, all MCP-verified, all documented in `sample-data-trace.md`.

**Reality check on Shippo sandbox lifecycle states:** Shippo's test carriers (UPS test, USPS test) do NOT advance states automatically — they stay at `PRE_TRANSIT` or whatever the initial state is. To get other states, we either (a) use the special carrier `shippo` (Shippo's own test carrier which DOES return varying states based on the `address_to` you pick — some Shippo-internal magic addresses return `TRANSIT`, `DELIVERED`, etc.) or (b) we POST simulated tracking-update events to our own webhook endpoint and let our code set the state. We use (a) where possible and (b) as fallback. Both are valid for "operators see Shippo activity in different states."

### Seed procedure

Run from local docker via the Shippo MCP tools or `bin/magento shipping_shippo:smoke-rate` with extensions; developer agent picks the most direct path. Shown as MCP calls for clarity.

**Seed 1 — PRE_TRANSIT (label purchased, no movement)**
```
1. mcp__shippo-mcp__shipments-create with:
   - address_from: Tikha origin (Tbilisi, GE)
   - address_to: a generic US address
   - parcel: 500g, 20×15×5 cm, declared $25
   - async: false
2. Pick the cheapest UPS or USPS rate from response
3. mcp__shippo-mcp__transactions-create with the rate.object_id, label_file_type=PDF
4. Record: transaction.object_id, tracking_number, label_url, status=SUCCESS, tracking_status=null/PRE_TRANSIT
5. mcp__shippo-mcp__transactions-get → verify
```

**Seed 2 — PRE_TRANSIT with first event recorded**
```
1. Same as Seed 1
2. After label purchase, simulate one tracking update via mcp__shippo-mcp__tracks-create-test (Shippo offers a test track endpoint to inject events)
   OR if not available: skip — Shippo's PRE_TRANSIT state is the resting state immediately after label purchase, so Seed 2 is functionally a duplicate of Seed 1 unless we can inject. Verify capability before deciding.
3. Record final status
```

**Seed 3 — TRANSIT**
```
1. Use the shippo test carrier (carrier=shippo)
2. address_to street_1 = "TRANSIT" (Shippo magic address — verify against Shippo docs at shippo.com/docs/tracking/testing)
3. Create shipment + transaction
4. Wait or poll mcp__shippo-mcp__tracks-get until tracking_status.status == TRANSIT
5. Record
```

**Seed 4 — DELIVERED (full lifecycle)**
```
1. Use the shippo test carrier (carrier=shippo)
2. address_to street_1 = "DELIVERED" (Shippo magic address)
3. Create shipment + transaction
4. Poll until tracking_status.status == DELIVERED
5. Record
```

**Seed 5 — RETURNED**
```
1. Use the shippo test carrier
2. address_to street_1 = "RETURNED" (Shippo magic address — verify exact spelling)
3. Create shipment + transaction
4. Poll until tracking_status.status == RETURNED
5. Record
```

**If Shippo's "magic address" trick is not available in sandbox** (developer agent verifies as the first action of Phase D), fallback plan: create 5 PRE_TRANSIT transactions and use our webhook simulation (same code path as Phase A step 5) to drive state changes locally, recording the final state in `sample-data-trace.md`. The trace doc must be explicit about whether each state was real-from-Shippo or locally-simulated.

### Output

`~/module-shipping-shippo/docs/sample-data-trace.md`:

```
# Shippo Sample Data — 5 sandbox transactions
# Seeded: 2026-04-25 by Session 5 / Phase D

| # | object_id | tracking_number | carrier | state | label_url | MCP-verified |
|---|---|---|---|---|---|---|
| 1 | <id> | <num> | usps_test | PRE_TRANSIT | <url> | yes |
| 2 | ... | ... | ... | PRE_TRANSIT (simulated) | ... | yes |
| 3 | ... | ... | shippo | TRANSIT | ... | yes |
| 4 | ... | ... | shippo | DELIVERED | ... | yes |
| 5 | ... | ... | shippo | RETURNED | ... | yes |

Verification commands run:
  mcp__shippo-mcp__transactions-get for each object_id → status SUCCESS
  mcp__shippo-mcp__tracks-get for each tracking_number → state matches table
```

**Cleanup decision:** do NOT delete these transactions at end of Phase D. They are deliberately persistent so future operators see them in the Shippo dashboard. Delete only the transactions from Phase A (lifecycle test) and Phase C (multi-merchant test) which are throwaways.

---

## 6. Go-live runbook outline

`/home/nika/duka/docs/runbooks/shippo-go-live.md`

### Section headings + key contents

```
# Shippo Go-Live Runbook
# Audience: Nika (operator) executing the sandbox-to-live flip
# Last validated: 2026-04-25 (Session 5)

## Pre-flight checklist
- [ ] Session 6 (checkout integration) has shipped — Shippo is selectable at checkout
- [ ] Sandbox flow tested end-to-end at least once on prod with sandbox key
- [ ] Two real carrier accounts ready to connect (recommend UPS + USPS for US shipments, DHL for international); document Wolt Drive / Trackings.ge are NOT supported via Shippo and have separate adapter timelines
- [ ] Sentry + /shubo_ops/health dashboards are open in browser tabs

## Pricing reality
- Pay-as-you-go: $0.05/label, no monthly fee. Recommended for first 90 days.
- Pro: $10/month + $0.05/label. Switch when label volume > 200/month (breakeven).
- NEVER pick Premier ($600/month) — overkill until Year 2.

## Step 1 — Sign up + connect carriers
- URL: https://goshippo.com/pricing → Pay-as-you-go
- Use the company email (giorgishubitidze.work@gmail.com or a shubo.ge alias)
- Verification: Shippo dashboard shows account active
- In dashboard: Settings → Carriers → connect each carrier with their respective merchant credentials
- Verification: each carrier shows "connected" status

## Step 2 — Generate live API key
- Dashboard → Settings → API Tokens → Create Live Token
- Save to ~/.shippo-key-live on local (chmod 600)
- DO NOT commit; DO NOT echo to logs
- Verification: `curl -sS -H "Authorization: ShippoToken $(cat ~/.shippo-key-live)" https://api.goshippo.com/addresses/ -w "\nHTTP %{http_code}\n"` → 200

## Step 3 — Configure prod (encrypted backend)
- ssh deploy@178.104.167.156 "echo -n '$(cat ~/.shippo-key-live)' | docker exec -i duka_php php bin/magento config:set --scope=default --scope-code=0 shubo_shipping_shippo/api/key --value-from-stdin"
- ssh deploy@178.104.167.156 "docker exec duka_php php bin/magento config:set shubo_shipping_shippo/api/mode live"
- ssh deploy@178.104.167.156 "docker exec duka_php php bin/magento cache:flush"
- Verification: `bin/magento shipping_shippo:smoke-rate` (run on prod) returns rates with non-test carrier names

## Step 4 — Register live webhook
- curl -X POST -H "Authorization: ShippoToken $(cat ~/.shippo-key-live)" \
    -H "Content-Type: application/json" \
    -d '{"url":"https://duka.ge/shipping_shippo/webhook","event":"track_updated","is_test":false}' \
    https://api.goshippo.com/webhooks/
- Capture returned object_id; persist somewhere (admin notes? config table?) so we can later DELETE it for cutover
- Verification: `mcp__shippo-mcp__webhooks-list` shows both the sandbox webhook (already there) and the new live webhook
- Save the live webhook_secret to prod via the same encrypted-config flow as the API key
- IMPORTANT: do NOT delete the sandbox webhook — it stays for testing. The HMAC verifier handles whichever signature matches the incoming event's mode.

## Step 5 — Enable carrier at checkout
- ssh deploy@178.104.167.156 "docker exec duka_php php bin/magento config:set carriers/shippo/active 1"
  (NOTE: this config path assumes Session 6's design — verify against the actual config.xml block before running)
- ssh deploy@178.104.167.156 "docker exec duka_php php bin/magento cache:flush"
- Verification: place a test cart on prod, reach checkout shipping step, see Shippo rates appear

## Step 6 — Live smoke order ($5 max)
- Place a real order with the smallest cheapest item against a known-good US address
- Spend cap: $5 INCLUDING shipping label cost (~$3 USPS Ground + $0.05 Shippo fee + product)
- Verify label PDF arrives, tracking number is real, shipment can be cancelled if needed (Shippo refund within 28 days)
- Verification: `mcp__shippo-mcp__transactions-get` for the live transaction → status SUCCESS
- ROLLBACK if the smoke order fails any check: jump to Section "Rollback"

## Step 7 — Monitor 24h
- Sentry: filter `module:shipping-shippo` for any exceptions
- /shubo_ops/health: Shippo carrier status green
- Webhook deliveries: Shippo dashboard → Webhooks → confirm ≥80% delivery success
- If any red: Rollback section

## Rollback
- IMMEDIATE flip back to sandbox: `bin/magento config:set shubo_shipping_shippo/api/mode test && bin/magento cache:flush`
- Risk: any in-flight live shipments KEEP their live state but new shipments will hit sandbox. Document each in-flight live shipment by tracking_number; Shippo dashboard remains the source of truth for those even after our config flips.
- DELETE the live webhook if the issue was webhook-related (sandbox webhook continues working)
- Open Sentry post-mortem; do not re-flip to live until root cause documented + design doc updated

## What this runbook does NOT cover
- Wolt Drive integration (separate adapter, separate runbook)
- Trackings.ge integration (separate adapter, separate runbook)
- Insurance / customs declarations (Shippo supports both; not yet wired in `Shubo_ShippingShippo`)
- Bulk label purchase (Shippo supports up to 1000/batch; not yet wired)
```

---

## 7. Hand-off checklist for the developer agent

The developer agent receiving this design doc should:

1. Read this doc end-to-end first.
2. Confirm Phases A-G are understood; ask architect for clarification before any deviation.
3. Run Phase A's MCP availability check as the very first command.
4. After every Phase, write its trace doc and commit before starting the next.
5. Use the standalone repo `~/module-shipping-shippo/` as the canonical source for ALL PHP changes (per `reference_module_distribution.md` — Shippo is a Vendor module). Run `composer update shubo/module-shipping-shippo` in `/home/nika/duka` after the standalone changes are tagged + pushed; commit `composer.lock` to duka.
6. The pre-push hook (`check_public_module_sync`) does NOT cover Vendor modules; do not be alarmed when it stays silent on the duka push.
7. All trace docs live under `~/module-shipping-shippo/docs/`, NOT `app/code/Shubo/ShippingShippo/docs/` (which doesn't exist — the in-tree copy is in `vendor/shubo/module-shipping-shippo/` and is composer-managed, not edited).

---

## 8. Architect sign-off

Path B chosen. Plan is honest about what's deliverable in 4-6 hours given the verified gap. Session 6 is queued with full design coverage so this isn't a punt — it's a sequencing decision.

If reviewer disagrees with this scope reduction during Phase F sign-off, escalate to user (Nika) directly with a 2-line summary; do not rewrite the plan unilaterally.

— Architect, 2026-04-25
