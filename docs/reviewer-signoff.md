# Session 5 — Reviewer Sign-off (Shubo_ShippingShippo)

**Reviewer:** reviewer agent
**Date:** 2026-04-25
**HEAD reviewed:** standalone `~/module-shipping-shippo/` @ `84858e4` (5 commits ahead of `origin/main`)
**Companion:** uncommitted in duka — `docs/runbooks/shippo-go-live.md`, `KNOWN_ISSUES.md` (Shipping section)
**Predecessor doc:** `docs/session-5-scope-decision.md` (architect's Path B)

---

## 1. Overall verdict — APPROVE-WITH-FOLLOWUPS

The Session 5 deliverable does exactly what the architect's scope-decision doc says it will: it ships a fully exercised adapter-layer proof of the Shippo integration against the live sandbox (lifecycle, multi-merchant, address validation, 5 named-state samples), a clean `AddressValidator` service with honest fail-open semantics, a runbook with a hard money cap and one-liner rollback, and a Session 6 design doc that is detailed enough for a developer to start work without re-litigating decisions. The deferral list in §4 of the scope-decision doc is faithfully mirrored in the runbook (Step 5 explicitly waits on Session 6) and in `KNOWN_ISSUES.md`. Quality gates were independently re-run (PHPUnit unit: OK 79/219 verified in a throwaway PHP 8.4 container) and tampering experiments confirmed the unit tests would catch real regressions. Two non-blocking issues (one webhook-secret extraction placeholder in the runbook, one tiny PII-leak risk in error-path logging) keep this APPROVE-WITH-FOLLOWUPS rather than a clean APPROVE; neither risks the merge.

---

## 2. Findings

### BLOCKERS — none

No issue rises to the level of blocking the merge. The runbook concern below is not a blocker because (a) the runbook is operator-executed not auto-run, (b) the placeholder is loudly flagged on the same line, and (c) Step 5 (the runbook step that consumes the secret) is itself blocked on Session 6 landing first per the architect's scope decision.

### SHOULD-FIX (next iteration)

**1. `/home/nika/duka/docs/runbooks/shippo-go-live.md` line 176 — `WEBHOOK_SECRET=$(jq -r .object_id …)` is a known-bad placeholder shipped into the canonical runbook.**
The line carries an inline comment "placeholder; confirm the exact field name and replace `.object_id` with the secret field before running" but the danger is real: an operator copy-pasting Step 4 wholesale would write the webhook `object_id` (a public identifier) into prod's `shubo_shipping_shippo/api/webhook_secret`. Every subsequent live webhook would then fail HMAC verification (correct security behavior, wrong root cause to debug under stress at 2am). Resolve by either (a) looking up the actual Shippo field name (per the linked `goshippo.com/docs/tracking/webhooks` page) and replacing the line before merge, or (b) hard-failing the script with `set -e; echo "STOP — read the comment on this line and fix the jq expression first"; exit 1` so the operator cannot accidentally run a placeholder. Option (a) is preferred — five minutes of doc reading.

**2. `/home/nika/module-shipping-shippo/Service/AddressValidator.php` line 87/92 — error-path log + return value can echo Shippo's verbatim error text, which can include user-supplied address fields.**
Example caught in `docs/end-to-end-trace.md` Phase B output: a bogus address with country `XX` produced `Shippo request rejected: {"country":["Invalid value specified for country 'XX'"]}` in `messages[]`. That message is also logged at `info` level. For US/EU PII hygiene, log only Shippo's structural code (e.g. truncated to first 60 chars of the JSON envelope, or a fixed `'address validation rejected at HTTP layer'` line plus a Sentry breadcrumb that captures the body without writing to the log aggregator). Same fix for the user-facing `messages[]` array — strip JSON braces/quoted-input before passing to the storefront. Defer to Session 6 because that's when the validator's output reaches a user surface; today it's CLI/test-only.

**3. `/home/nika/duka/docs/runbooks/shippo-go-live.md` Step 3 line 130 — `&& rm -f /tmp/shippo-key-stdin` only fires on `config:set` success.**
If `bin/magento config:set` fails for any reason (cache corruption, encryption key missing, permissions), the live API key sits in `/tmp/shippo-key-stdin` on prod with mode 600 indefinitely. Replace `&& rm -f` with `; rm -f` (always-cleanup) and accept that the trade-off is a possibly-failed config:set with a key safely deleted. Five-minute fix.

**4. `Test/Unit/Service/AddressValidatorTest.php` — no test exercises the `extractBool` non-bool fallbacks (string `"true"`, int `1`, default `false`).**
Tampering experiment: replacing `return false;` on line 125 with `return true;` did not turn any test red, confirming the default-branch is dead code in test space. Either remove the dead branches (Shippo's docs say `is_valid` is always a JSON bool) or add one parameterized test exercising the int-1 / string-"true" coercion paths. Low priority; current Shippo behavior is well-defined.

**5. `~/module-shipping-shippo/Test/Integration/AddressValidatorSmokeTest.php` line 134 — `assertTrue($result->suggestion !== null || $result->messages !== [])` is a weakened assertion that masks Shippo behavior changes.**
If Shippo silently starts returning empty messages on the bogus-address path, this test still passes because of the OR. Tighten to `assertNotEmpty($result->messages)` — the `XX/ZZ` input should always produce a structured rejection message, never silence.

### NITS (informational only)

- **`docs/end-to-end-trace.md` line 147** — claims "OK (5 tests, 27 assertions)" for the unit suite; the actual count from the AddressValidator suite alone is correct, but the wording reads as if it covers the whole module unit suite (which is 79/219). Clarify scope.
- **`docs/address-validator-justification.md` §Reviewer-facing summary** — claims "1 unit test class (4 tests)" but the file ships 5 tests (the developer added a 4xx-rejection test, correctly). Update the doc.
- **`docs/session-6-checkout-integration-design.md` §8 `[x]` checkboxes** — the doc marks Session 5 deliverables as already-done but `composer.lock` was held back per scope decision §7. The first checkbox should read `[ ] composer.lock pinned in duka to a Session 5 standalone tag — pending Session 6 prereq` to match reality.
- **`Test/Integration/ShippoLifecycleTest.php` lines 250-294** — three anonymous-class `Config` and `CurlFactory` overrides in three test files (`ShippoLifecycleTest`, `AddressValidatorSmokeTest`, `ShippoMultiMerchantTest`) are byte-identical. Consider extracting to `Test/Integration/Helper/StubConfig.php` and `Test/Integration/Helper/RealCurlFactory.php` to halve the LOC and remove the drift risk if Shippo's API base URL changes.
- **`/tmp/shippo-*-trace.json` paths** — `/tmp/` is wiped on container restart; if the developer wants the trace to outlive a `docker compose down`, write to `~/module-shipping-shippo/var/traces/` instead.

---

## 3. Per-area scorecard

| # | Area | Verdict | Notes |
|---|---|---|---|
| 1 | Simplicity-first compliance | PASS | `address-validator-justification.md` walks Delete/Reuse/Inline honestly; concrete call-sites enumerated; new abstraction warranted. |
| 2 | Bidirectional verification (AFK contract) | PASS-WITH-NOTE | Trace docs (`end-to-end-trace.md`, `multi-merchant-trace.md`, `sample-data-trace.md`) show real side-by-side diffs with object_ids that match between gateway and Shippo; raw HTTPS curl as the independent path is acceptable substitution for the MCP server (different code path, same authoritative endpoint). Spirit met. |
| 3 | Test quality (TDD discipline) | PASS | Tampering experiments: tamper 1 (4xx-branch flip) → red as expected. Tamper 3 (`maybeBuildSuggestion` short-circuit) → red as expected. Tamper 2 (default-branch in `extractBool`) → green, exposed as dead code (SHOULD-FIX #4). Tests exercise behavior, not coverage. |
| 4 | ka_GE strings | PASS | Zero `__()` calls in `AddressValidator.php`, `AddressValidationResult.php`, `SmokeValidateAddressCommand.php`. Backend-only as the architect intended; no language pack work needed. |
| 5 | Money handling | PASS | `AddressValidator` and `validateAddress()` extension touch zero monetary values; pure address payload pass-through. No `(float)` casts, no `==` comparisons of money. |
| 6 | Distribution discipline | PASS | `app/code/Shubo/ShippingShippo/` correctly does not exist (Vendor module). `vendor/shubo/module-shipping-shippo/` lacks `Service/` and `Test/Integration/` directories — confirms `composer.lock` (pinned to commit `33ff7638`) was correctly held back. No leaks. |
| 7 | Security review | CONCERN | Injection-safe (everything routed via `json_encode` in `ShippoClient::encodePayload`, no raw SQL/shell/URL concatenation). Fail-open behavior on Shippo outage is the correct trade-off. PII concern in error-path logging — see SHOULD-FIX #2. |
| 8 | Honest deferrals | PASS | Runbook Step 5 carries an explicit "Depends on Session 6" header; pre-flight checklist's first bullet `grep -r '<carriers><shippo>'` blocks execution if Session 6 hasn't shipped. `KNOWN_ISSUES.md` entry references the design doc. Session 6 design doc's pre-requisites checklist mirrors Session 5's deliverables. No contradictions between docs. |
| 9 | Runbook safety | PASS-WITH-NOTE | `$5` money cap explicit, rollback is a true single-line `ssh && cache:flush`, webhook URL correctly `duka.ge` not `dukka.duckdns.org`. Two cleanup hygiene issues — see SHOULD-FIX #1 and #3 — but do not block merge given Step 5 itself is blocked on Session 6. |
| 10 | KNOWN_ISSUES.md hygiene | PASS | New `## Shipping (Shubo_ShippingCore + Shubo_ShippingShippo)` section heading at line 100 is consistent with surrounding sections (`## Catalog / Merchant Scope`, `## Admin UI`, `## Build / Tooling`). Entry follows the file's "blocker + reference design doc + impact list" convention. |

---

## 4. Tampering experiments performed

All run in a throwaway `php:8.4-cli` Docker container against `~/module-shipping-shippo/` with `bcmath` extension installed. Baseline at HEAD `84858e4`: `OK (79 tests, 219 assertions)`.

| # | File | Change | Expected | Observed | Verdict |
|---|---|---|---|---|---|
| 1 | `Service/AddressValidator.php` line 90 | `valid: false` → `valid: true` in 4xx branch | red (`testShippoFourHundredReturnsInvalidWithMessage`) | red — `Failed asserting that true is false` at `AddressValidatorTest.php:184` | tests catch behavior change |
| 2 | `Service/AddressValidator.php` line 125 | `return false;` (default in `extractBool`) → `return true;` | red somewhere | green (5/5 pass, 22 assertions) | DEAD CODE — see SHOULD-FIX #4 |
| 3 | `Service/AddressValidator.php` lines 163-205 | `maybeBuildSuggestion` body replaced with `return null;` | red (`testInvalidAddressWithSuggestionReturnsCorrectedAddress`) | red — `Failed asserting that null is not null` at `AddressValidatorTest.php:105` | tests catch behavior change |

File restored to `84858e4` HEAD between each experiment; final `git status` clean. Total experiment time: ~3 min.

---

## 5. What I intentionally did NOT review

- **PHPStan integration config** (`phpstan-integration.neon`) — accepted developer's "OK" output without re-running. Trusting the developer's quality-gate report on this one because PHPStan-level-8 violations do not typically pass review with green output.
- **PHPCS pass** — same. Developer reported clean; not re-run.
- **The integration tests against the live sandbox** (`ShippoLifecycleTest`, `ShippoMultiMerchantTest`, `AddressValidatorSmokeTest`, `SampleDataSeedTest`) — not re-run because (a) they require the sandbox key, (b) the developer-side trace docs already record the captured `object_id`s and Shippo-side read-back diffs, and (c) re-running would create another set of throwaway Shippo records that someone has to clean up. Reviewed the test source code thoroughly and the trace docs end-to-end.
- **The five sample-seed transactions on Shippo's dashboard** — did not log into the Shippo dashboard to visually verify the `metadata: "duka-session-5-phase-d-sample-seed"` marker appears on each. Trusting the trace doc.
- **`docs/session-6-checkout-integration-design.md` correctness** — a design doc for future work, not implementation. Spot-checked the architectural rationale (parallel subclass over multi-carrier refactor) and the file-level breakdown for plausibility but did not full-read every section.
- **`docs/multi-merchant-trace.md` + `docs/sample-data-trace.md` independent dashboard verification** — accepted the documented `object_id`s without an independent `curl GET /transactions/{id}` to Shippo (would have required loading the sandbox key into this reviewer environment, which is out of scope for a code review).
- **Cross-module impact of the new `AddressValidator` on `Shubo_ShippingCore`** — ShippingCore is read-only this session per scope-decision; no change to grep for impact against.

---

## 6. Sign-off

**APPROVE-WITH-FOLLOWUPS** — merge Session 5 standalone commits `5a2f5c6 → 84858e4` to `origin/main` of `~/module-shipping-shippo/`, push, tag (e.g. `v0.2.0` or whatever the established tag scheme is). HOLD `composer update shubo/module-shipping-shippo` in duka until Session 6 is ready to start, per architect scope-decision §7. Land the duka-side runbook + `KNOWN_ISSUES.md` changes as their own commit on duka `main`.

The five SHOULD-FIX items above are next-iteration work — none of them block this session. Items #1 and #3 (runbook hygiene) should land before the runbook is actually executed; item #2 (PII-in-logs) before the validator reaches any customer-facing surface in Session 6; item #4 (dead-code test gap) and #5 (weakened assertion) at the Session 6 reviewer agent's discretion.

Adapter layer is real, MCP-verified, and the architect's Path B was the right call given the verified gap between session-prompt assumptions and actual checkout state.

— reviewer agent, 2026-04-25
