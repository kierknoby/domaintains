# Operator Integration Contracts

The interfaces in [src/Contracts.php](src/Contracts.php) are public extension
contracts, not assertions about an installed platform. Implement them independently
with the operator's existing authority and storage. Fixture success is not proof
of real authentication, pricing, payment, reservation or durable recovery.

## Customer And Catalog

`CustomerAccess::current(array $session, array $server): ?CustomerIdentity`
returns an authenticated opaque account ID or null. Never authenticate with a
browser-supplied account ID or a PBX activation token. `requireMutation(customer,
session, server): void` enforces current authenticated authority, request method,
CSRF and operator policy; it throws before any mutation adapter is invoked.

`SelectionCatalog::resolve(CustomerIdentity $customer, string $selector): SelectionContext`
resolves an operator selector to an existing owned eligible resource and product.
The runtime independently checks the returned account and configured product.
Selectors used by the orchestration are `cart-<opaque item ID>` and
`update-<opaque subscription ID>`. They are lookup keys, not claims of ownership.
Check subscription status, entitlement and attachment policy authoritatively.

`SelectionContext(kind, accountId, productId, resourceId)` uses nonempty strings;
kind is `cart`, `subscription` or `order`. Its scope hashes all four fields.
Stable resource IDs must not be reused for replacement items or unrelated owners.
No database table, numerical account/product/service representation or session
key is part of these contracts. Provider authorisation, BYOT attachment and
emergency registration remain their existing independent authorities.

## Ordering Platform

| Method | Result and required behaviour |
| --- | --- |
| `cartItems(CustomerIdentity): array` | Current owned cart `SelectionContext` objects, including unrelated products which the orchestrator skips. |
| `selection(CustomerIdentity, string $itemId): string` | Actual saved operator selection, not an independent browser receipt. |
| `saveSelection(CustomerIdentity, string $itemId, string $canonical): void` | Recheck ownership/eligibility and store only the canonical selection, preserving unrelated metadata. |
| `withOrderLine(string $orderLineId, callable $operation): mixed` | Load and lock the actual saved line; independently verify its immutable originating-item relationship, payment/order eligibility and receipt mapping; hold authoritative assignment/idempotency protection through the callback. |

The order callback receives:

```php
[
    'order_line_id' => 'order-line-a',
    'account_id' => 'account-a',
    'product_id' => 'voice-plan',
    'selection' => '442041234567',
    'originating_item_id' => 'item-a',
    'selection_receipt' => $persistedServerReceipt,
]
```

`originating_item_id` is mandatory, nonempty and supplied only after the adapter
verifies the persisted source-item/order-line relationship. Establish it at
authoritative order creation and do not permit rebinding. Never derive it from
browser input, the receipt itself or a mutable cart index. Verify account, product
and saved selection under the existing guards. The reference adapter's
`createOrderLine` demonstrates a separate immutable in-memory snapshot, not
production durable persistence or an additional public platform interface.

When a receipt is supplied, `resource_id` must equal the independently verified
`originating_item_id`. Missing receipt resource IDs, source mismatches and missing
or unverified mappings must fail closed before carrier transport or allocation;
a fresh carrier offer cannot bypass these checks. Keep the original account,
product, number, timestamp, expiry and idempotency requirements too.

The receipt remains optional only for an order with a verified source mapping;
without valid offer evidence the number must appear in the fresh carrier batch.
Reject cancelled, unknown, foreign, unpaid/ineligible or
already-fulfilled lines. Bind any receipt immutably to its actual source item
and created order line. Callback results pass through; exceptions must block
fulfilment. This in-process API must never be exposed as a customer HTTP endpoint.

`OrderingLifecycle::register(validateSelection, beforeCheckout, beforeAllocation)`
receives callbacks with these signatures:

| Callback | Inputs | Return |
| --- | --- | --- |
| validateSelection | `(string $itemId, string $selection)` | Canonical stored number string |
| beforeCheckout | No arguments; trusted current request/session | List of verified server receipts |
| beforeAllocation | `(string $trustedOrderLineId)` | Confirmed provisioning result after billing reconciliation |

Map callbacks to the operator's actual authenticated update, checkout acceptance
and eligible fulfilment stages. Propagate errors using the real platform's error
convention; never swallow failures or treat registration as proof of invocation.
Protect non-browser fulfilment independently. Keep normal order/subscription
creation, commercial entitlement and payment authority in the ordering platform.

Receipts contain `resource_id`, `account_id`, `product_id`, `number`, `offered_at`,
`verified_at`. Persist only server-produced receipts with the correct line.
`offered_at` preserves actual carrier-offer age; `verified_at` does not renew it.
Receipts are evidence, not paid orders, signed client claims or reservations.

## Cart Form Binding

Each mounted cart context owns exactly one input. Without explicit `fieldId`, the
shared binder uses `selectionFieldName[contextToken]`; the token encodes each code
point of the exact context as hexadecimal joined by `-`. Controller mappings must
resolve tokens against authoritative item IDs before invoking validation. For
existing operator inputs, use a unique `fieldId` and a unique submission name.
Missing/ambiguous IDs, duplicate names, claimed fields and duplicate context mounts
are rejected rather than borrowing another item's field. Each context has its own
radio group and Refresh DOM ID. These client identifiers never replace catalog,
customer or server-side cart ownership authorisation.

## Selection Evidence

`OfferEvidence::seenAt(state, scope, prefix, canonical): ?int` returns the original
server-offer time. `remember(array &$state, scope, prefix, numbers, int $now): int`
stores authenticated carrier offers and returns the number of newly observed
offers. Each list item is `['number' => canonical, ...]`. `clear(array &$state): void`
invalidates evidence on nonce rotation. Preserve scope isolation, expiry and
locking in any persistent implementation; do not import browser-provided timestamps.
[src/Evidence.php](src/Evidence.php) implements bounded session evidence.

Trusted order verification can seed evidence only from authoritative persisted
receipts. Any separate reservation service must have its own verified expiry,
ownership and carrier guarantees; the public fallback does not reserve a number.

## Provisioning And Billing

`ProvisioningAdapter::preflight(SelectionContext): void` checks authoritative
allocation/activation/attachment readiness. `allocateAndActivate(context,
canonical): array` rechecks these conditions under operation guards, allocates and
activates using existing provider policy, and returns at least:

```php
['confirmed' => true, 'number' => '442041234567']
```

Confirmation must describe the actual matching number and complete intended
activation, not simply an HTTP success. Record partial/unknown outcomes durably
before throwing. Do not alter unrelated PBX objects, BYOT state, emergency Caller
ID, routes or existing entitlement rules. Duplicate number/assignment protection
must span concurrent operations and customers, not merely one browser session.

`BillingIntegration::withOperation(context, canonical, callable): mixed` supplies
durable operation/idempotency guards across the entire callback, including bounded
availability waits and provisioning. `preflight(context, canonical): void` verifies
pricing/commercial readiness without committing billing. `reconcile(context,
canonical, array $provisioned): void` performs the operator's normal billing and
subscription reconciliation only after confirmed provisioning. It must throw on
failure, preserve partial outcomes and never falsely report completed billing.

The orchestration order is billing guard, billing preflight, provisioning preflight,
fresh availability verification, allocation/activation confirmation, billing
reconciliation. On exception the guard must durably retain the state requiring
review. An ordinary database rollback cannot undo a remote carrier allocation.
Never automatically retry uncertain or completed operations; use explicit audited
operator recovery and independently verified outcomes. The in-memory fixture guard
demonstrates this flow only; production persistence and cross-process locking
remain adapter acceptance requirements.

## Carrier Availability

`AvailabilityCarrier::available(string $range, int $size): array` receives an
11-character national UK 01/02 range using digits and `_`, with size 20. Return
`['http_code' => int, 'body' => string, 'curl_errno' => int]`; success body is a JSON
list of number strings. Throw or return failure for transport/HTTP errors. Use
bounded verified TLS transport, bounded bodies and no unsafe redirects or retries.
Never allocate here. Broad carrier responses are intentionally retained for
client-side all-term OR filtering after canonicalisation, area checks and deduping.

All availability consumers must use the same transport protection. Inventory
other pages, workers, scheduled jobs and gateways before migration; parallel old
and new lock files multiply traffic rather than sharing protection. Other hosts
need a common availability gateway or a separately verified distributed limiter.

## Evidence Limits

The search algorithm is preserved from authoritative input. Its executable-source
fingerprint is asserted after reversing the configurable endpoint, context
transmission and context-qualified radio/Refresh identity wiring, and excluding
comments. No search/matching/request algorithm edits are introduced by identity
isolation. Removing an identifying comment does
not change the engine. No private paths, vendor hooks or private billing code are
published here. Installed adapter mappings and all real financial/carrier guarantees
must be verified locally by the operator; the public contracts do not invent them.