# Address Validator — Simplicity-First Justification

Per CLAUDE.md "Simplicity-first" decision tree, this doc records why the new abstraction `Shubo\ShippingShippo\Service\AddressValidator` is added in Phase B (Session 5) before any PHP lands.

The author MUST evaluate, in order:
1. Delete — can the need be removed entirely?
2. Reuse — has another Shubo_* module solved this with a pattern we can copy?
3. Inline — is a direct method call clearer because the relationship is 1:1, stable, and within one module?

Only after 1-3 are ruled out does a new abstraction get introduced.

## (1) Delete — can the need be removed?

**No.** Address validation is a real business need for Shippo and for the eventual checkout integration in Session 6:

- **Pre-purchase rate-quote sanity check:** Shippo's rate API silently returns zero rates for malformed addresses (no HTTP error, no `messages[]` entry beyond a generic "no rates available"). Without an explicit address-validate call, the customer sees "no shipping options" with no actionable feedback.
- **Customer-facing suggestions at checkout:** Shippo's `validation_results.messages[]` returns concrete corrections (e.g., "Did you mean 1600 PENNSYLVANIA AVE NW?"). Surfacing these to the customer reduces failed deliveries and merchant write-offs.
- **Admin-side label-purchase preflight:** before paying for a label that will be returned, the admin shipment flow can validate the cleaned destination first.
- **Operational telemetry:** counting "addresses sent for validation that came back invalid" gives the merchant ops team a leading indicator of catalog data quality issues.

The need cannot be eliminated — it is the canonical integration point for address quality at the carrier boundary.

## (2) Reuse — has another Shubo_* module solved this?

**No.**
- `Shubo_ShippingCore` deliberately has no validator abstraction. Address validation is carrier-specific (Shippo validates against USPS-derived databases for US addresses, against DHL/regional databases internationally; another adapter like Wolt would validate differently or not at all). Pushing a validator into Core would either bake Shippo assumptions in or force a polymorphic abstraction we don't have a second implementation to justify.
- `Shubo_RsGeIntegration` has rs.ge TIN-validation, not address validation, and uses SOAP — not transferable.
- `Shubo_TbcPayment` / `Shubo_BogPayment` validate billing addresses at the payment gateway, not delivery addresses, and the validation surface is a different shape (single-field passes/fails, no suggestions).

Nothing exists to reuse.

## (3) Inline — is a direct method call clearer?

**No.** The eventual call sites are not 1:1 and not stable:

- **Smoke CLI** (`bin/magento shipping_shippo:smoke-validate-address`) — operator tool, lives forever.
- **Rate-quote pre-flight** (Session 6) — invoked from `ShippoCarrierGateway::quote()` or a wrapping orchestrator; a checkout request could call it dozens of times as the customer types.
- **Admin shipment creation** (Session 6) — invoked when the admin clicks "Ship", before the label-purchase HTTP call.
- **GraphQL `validateShippingAddress` mutation** (Session 7+) — a PWA front-end debounced address-typing UI calls this directly.

Three to four call sites with different latency / failure-mode budgets (smoke = synchronous, blocking; rate quote = synchronous, soft-fail; admin = synchronous, hard-fail; GraphQL = asynchronous, debounced). Inlining the Shippo `POST /addresses/validate` call into each would duplicate the request-shaping, the suggestion-extraction, and the fail-open logic four times. A single service is the smaller code surface.

## Decision

New abstraction approved.

- Class: `Shubo\ShippingShippo\Service\AddressValidator`
- DTO: `Shubo\ShippingShippo\Api\Data\Dto\AddressValidationResult` (readonly)
- Constructor deps: `ShippoClient`, `LoggerInterface`. NOT `ScopeConfig` directly — the API key path is already encapsulated in `ShippoClient` via `Config`.
- Wraps `POST /addresses/validate`.
- Returns the strict DTO (`bool $valid`, `?ContactAddress $suggestion`, `array<string> $messages`).
- Fail-open on Shippo 5xx / network — log warning, return `valid=true` with a synthetic message. Rationale: address validation is a quality-of-service guard, not a security gate; degrading shipping availability when Shippo is unhealthy would compound the outage.

The eventual checkout wiring is intentionally NOT in scope for Phase B (deferred to Session 6 per architect's `session-5-scope-decision.md`). Phase B delivers only the service, its unit tests, and a smoke CLI mirror of `SmokeRateCommand`.

## Reviewer-facing summary

- 1 service class
- 1 DTO
- 1 unit test class (4 tests)
- 1 smoke CLI command
- 1 di.xml binding
- ~250 LOC total
- Zero changes to other Shubo modules
- Zero changes to `ShippingCore` interfaces

This is the smallest abstraction that covers the four call sites without forcing each one to know how to talk to Shippo's address-validate endpoint.
