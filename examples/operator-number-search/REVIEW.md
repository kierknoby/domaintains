# Technical Review And Acceptance Gates

Review date: 2026-10-31. Development milestone: **DOMAINTAINS 0.7.0-dev**.
Independent code review is complete; this is not a stable release or production
approval. This operator integration is provider-neutral across
customer access, product/catalog authority, ordering, selection evidence,
provisioning, subscription billing and carrier availability.

## Local Assessment

| Milestone | Assessment |
| --- | --- |
| Public architecture | Separate configurable contracts; no vendor bootstrap, schema, field number or hook dependency. |
| Search fidelity | One canonical engine with executable-source fingerprint and existing behavioral tests. |
| Local simulations | Availability, ordering, billing/provisioning failures, process locking and JavaScript contracts. |
| Controlled staging | Requires real operator adapters, verified lifecycle mappings and acceptance below. |
| Production | Not approved; browser, real adapter, carrier and user acceptance remain outstanding. |

The engine's executable integration edits transmit context, configure the
availability URL and scope radio names/Refresh IDs by context. Search algorithms
are unchanged. A formerly identifying comment is neutralised; the test removes
comments and reverses those exact wiring edits before comparing against the
authoritative executable-source fingerprint.
Three OR terms, subscriber-only matching, all-term batch filtering, cache seeding,
six-request round robin, Search/Stop/Clear, Refresh, sharp overlaps, local paging,
selection persistence and no polling/retries remain covered.

## Review Corrections

- Customer/catalog/ordering APIs use opaque string identities, not vendor fields.
- Shared binding uses operator field names and explicit optional container IDs,
  with no framework template assumptions or duplicate selection mechanism.
- Multiple cart items in one form now have item-specific selection fields,
  independently checked radios and unique Refresh IDs; explicit operator fields
  must have unambiguous IDs/names and cannot be claimed by another picker.
- Receipt `resource_id` must match the independently verified immutable
  `originating_item_id` of its authoritative order line. Missing/unverified or
  changed mappings fail before transport/allocation, even with a fresh offer.
- Provisioning and billing have separate preflight, confirmation and reconciliation
  APIs. Partial results remain guarded and cannot be automatically retried.
- Picker nonces and service verification use the same configured clock; opaque
  contexts produce collision-free generated DOM IDs.
- Queue timing excludes failed carrier duration. Checkout retains original offer
  age, and denied attribution does not trust browser ownership claims.

Tests preserve cart/account/product evidence isolation, stable IDs through
reordering, no search/checkout allocation or billing, duplicate checkout rejection,
authoritative order eligibility, receipt expiry and matching provisioning results.
Synthetic adapters prove orchestration, not real payment or carrier ownership.

## Operator Adapter Gates

- [ ] Configure trusted customer authentication and mutation/CSRF controls, opaque
  product/resource mappings, authoritative area data and private configuration.
- [ ] Prove lifecycle callbacks run before accepting actual updates, checkout and
  fulfilment, and exceptions block those operations using the real error convention.
- [ ] Verify selection persistence through cart edits, login changes, ordering and
  subscription creation; bind server receipts to their actual immutable order lines.
  Verify same-form multi-item fields and the separately persisted origin mapping;
  test same-account/product receipt swaps, missing mappings and delayed fulfilment.
- [ ] Confirm payment/eligibility, assignment locks, durable idempotency and
  concurrent duplicate protection span all relevant workers and customers.
- [ ] Verify independent pricing preflight, matching allocation/activation confirmation,
  billing reconciliation failure and explicit uncertain-outcome recovery.
- [ ] Preserve existing provider ownership, entitlement, BYOT and emergency policies.
- [ ] Verify delayed-order evidence expiry and any separately implemented reservations.

## Shared Protection And Audit Gates

- [ ] Inventory every availability consumer and migrate to one limiter/gateway.
  Independent lock files or filesystems are not one protection boundary.
- [ ] Verify real cross-worker start spacing, bounded queues and disconnect handling.
  Do not delete/rotate a live limiter; drain consumers before changing its path.
- [ ] Confirm runtime ownership/modes, non-public logs/receipts/journals and safe backups.
- [ ] Approve lawful personal-data retention, closed-file rotation/deletion and access.
- [ ] Monitor queue/quota failures, audit warnings, incomplete CSV/JSONL pairs and
  uncertain provisioning/billing. Individual audit-file writes are not atomic pairs.

Operational records contain opaque verified account/product/subscription IDs,
connecting peer IP, action/prefix, result counts and separate transport/queue timing.
No full numbers, search digits, nonce, credentials or raw bodies are logged.
Transport invocation and HTTP success do not prove mask compliance, reservation,
allocation, billing, activation or emergency acceptance.

## Browser Gates

Genuine browser verification has not been performed. The cached Chromium binary
cannot start because `libatk-1.0.so.0` is missing; no tools were installed or
downloaded. The Node suite uses a simulated DOM and is not a visual/accessibility
conformance test. Open [tests/demo.html](tests/demo.html) for carrier-free checks,
then test the actual staging forms:

- [ ] Desktop 1440x900 and mobile 320x568/390x844: overflow, wrapping, readable
  numbers/recommendations and location controls, with screenshots.
- [ ] Single/double/triple sharp horizontal highlights and visible selected state.
- [ ] Invalid/duplicate fields, disabled controls, labels/aria-invalid/live messages.
- [ ] Keyboard focus, radios, Enter, Search/Stop/Clear and selection persistence.
- [ ] Local paging/Clear request counts, Find more and late/aborted responses with
  browser network interception; cached fixtures alone cannot prove cancellation.
- [ ] Actual cart/subscription forms, CSRF, checkout receipt mapping and proxy behavior.

## Separately Authorised Acceptance

- [ ] Verify carrier credentials/TLS, broad/masked responses, timeouts and actual
  spacing with approved low-volume requests. No external requests were made here.
- [ ] Confirm allocation/activation and billing on approved test numbers, competing
  reservations and explicit recovery from partial/uncertain operations.
- [ ] Obtain user acceptance for both workflows, privacy, recovery and performance.
- [ ] Approve release/deployment separately. No commit, push, staging, live system,
  external service, GitHub or separate website operation is part of this work.

See [README.md](README.md) for setup and [CONTRACTS.md](CONTRACTS.md) for exact
authority boundaries. Historical release/changelog text is unchanged.