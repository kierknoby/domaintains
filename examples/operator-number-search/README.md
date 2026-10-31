# Operator Number Search

Introduced in **DOMAINTAINS 0.7.0-dev**, an independently reviewed development
milestone, not a stable version, production release or deployment approval.

This PHP 8.2+ operator integration uses one canonical number-search engine for
cart orders and existing subscriptions. DOMAINTAINS is provider-neutral, including
ordering, subscription management and billing. No ordering or billing vendor,
database schema, numeric product field, private controller or hook is assumed.
The FreePBX module, activation, ownership, BYOT and emergency registration are
unchanged. Search and checkout verification never allocate, activate or bill.

## Architecture

Independent configured contracts cover:

| Responsibility | Contract |
| --- | --- |
| Customer authentication, authorisation and mutation/CSRF checks | `CustomerAccess` |
| Product and owned cart/subscription identification | `SelectionCatalog` |
| Cart storage and authoritative order-line lifecycle | `OrderingPlatform`, `OrderingLifecycle` |
| Scoped selection evidence | `OfferEvidence` |
| Allocation and activation confirmation | `ProvisioningAdapter` |
| Billing preflight, operation guards and reconciliation | `BillingIntegration` |
| Read-only carrier availability | `AvailabilityCarrier` |

Contracts and authoritative data shapes are in [CONTRACTS.md](CONTRACTS.md).
The separate classes in [fixtures/Adapters.php](fixtures/Adapters.php) implement
every responsibility with synthetic in-memory data. They are executable examples,
not production authentication, reservation, payment, persistence or locking.
Neither a private installation nor an external service is needed for local tests.

[public/dn-progressive-search.js](public/dn-progressive-search.js) is the sole
search engine. [public/number-picker.js](public/number-picker.js) provides shared
location controls, styling and configurable form binding. Runtime composition is
in [src/Runtime.php](src/Runtime.php); lifecycle orchestration is in
[src/Integration.php](src/Integration.php).

## Installation

1. Use isolated operator staging. Keep the package outside the document root;
   expose only the two public JavaScript assets and the PHP availability entry
   point through same-origin aliases, preserving its relative runtime include.
2. Require PHP 8.2+, JSON, POSIX and persistent local storage supporting `flock`.
   Use one dedicated unprivileged runtime account. Root access is rejected.
   cURL is required only for the optional supplied carrier transport.
3. Load the runtime interfaces before requiring [config.example.php](config.example.php).
   Make a private operator-owned configuration, mode 0600 or 0400, and set
   `DOMAINTAINS_NUMBER_SEARCH_CONFIG` to its canonical absolute path. Supply all
   adapters, an opaque string product ID, authoritative area data and HTTPS origins.
   The example is disabled and has null adapters; it cannot enable transport.
4. The optional `operator_bootstrap` callable establishes the operator's trusted
   session/request environment. Adapters may use that session or another verified
   identity mechanism. The runtime does not know an account's session-key layout.
5. Use canonical private state outside the document root, owned by the runtime
   account, mode 0700. Rate/audit files are 0600; protect parent directories too.
   All availability consumers for one carrier account must share this limiter.
6. Load [integration/operator.php](integration/operator.php) and call
   `domaintainsOperatorIntegration()->register()` at the operator's verified
   lifecycle registration stage. Registration and callback failures must block
   the corresponding operation; rendering alone is not checkout protection.
7. Wire the existing ordering and subscription controllers to the contracts below,
   run local tests and complete [REVIEW.md](REVIEW.md) before enabling staging.

Do not expose config, source, tests, fixtures, logs, receipts or credentials.
Keep session/evidence/quota updates under a trusted lock. The configuration is
trusted executable PHP, not customer input. Do not release session protection
while this service changes its state.

## Operator Configuration

This fragment belongs in private configuration after the runtime is loaded.
Every adapter variable is supplied by the operator; no platform helper is required.

```php
$config = require $operatorConfigTemplate;
$config['product_id'] = $operatorProductId;
$config['operator_bootstrap'] = $operatorBootstrap;
$config['customer_access'] = $customerAccess;
$config['catalog'] = $selectionCatalog;
$config['ordering'] = $orderingPlatform;
$config['ordering_lifecycle'] = $orderingLifecycle;
$config['evidence'] = $offerEvidence;
$config['provisioning'] = $provisioningAdapter;
$config['billing'] = $billingIntegration;
$config['carrier'] = $availabilityCarrier;
return $config;
```

Set `enabled` only after the adapters and staging gates are verified. Keep carrier
credentials private. [src/MagratheaAvailability.php](src/MagratheaAvailability.php)
is an optional replaceable read-only adapter, with verified TLS, no redirects,
5-second connect/12-second total timeouts and a 256 KiB response cap. Other carriers
implement `AvailabilityCarrier` with equivalent bounded transport protections.
The directory shape is `Territory => Region => national prefix => display label`.
The fixture directory is intentionally incomplete; supported numbers are UK 01/02.

## Cart And Subscription Binding

The operator catalog resolves opaque selectors such as `cart-item-a` and
`update-sub-a` to authoritative owned resources. Browser account, product,
subscription, availability and receipt claims are never authority. Stable item
IDs must survive cart reordering; replaced items must receive a new identity.
There is no assumed existing subscription for a new order.

Place the cart target inside the actual operator form, then render:

```php
echo \Domaintains\NumberSearch\Runtime::picker($config, $_SESSION, [
    'context' => 'cart-' . $itemId,
    'mode' => 'cart',
   'targetId' => 'operator-number-target-' . bin2hex($itemId),
    'selectionFieldName' => $config['selection_field_name'],
]);
```

Without `fieldId`, the binder creates/reuses an item-specific input named
`selectionFieldName[contextToken]`, where `contextToken` is each Unicode code point
of the exact `cart-<itemId>` context in hexadecimal, joined by `-`. Multiple items
may share a form and base field name without sharing selections. Map each token
back to its authoritative cart item in the operator controller; browser tokens
are not proof of ownership. Backend cart-item ownership checks are unchanged.

For an operator's existing input, supply its exact unique `fieldId`; its name and
value are retained. Existing fields must have distinct submission names in the
form. A missing/ambiguous field ID, duplicate name, already claimed field or
duplicate cart context does not mount a second binding. An unqualified base-name
input is never silently borrowed. Each picker has a context-qualified radio group
and Refresh ID. No numeric custom-field protocol is assumed. Optional
`anchorId` and `hideFieldContainerId` are operator-owned DOM IDs, not product
authorisation. No framework-specific container layout is inferred.

For subscriptions, load [integration/subscription.php](integration/subscription.php).
Render `domaintainsOperatorSearchPicker($subscriptionId)` in the existing form.
Its target/input/range/submit IDs use `bin2hex($subscriptionId)` as the suffix:
`dn-number-picker-target-`, `dn-number-to-add-`, `dn-number-range-`,
`dn-number-submit-`. Use a hidden selection input and prefix input, and an initially
disabled submit button. Alternatively supply explicit IDs through `Runtime::picker`.
On POST call `domaintainsOperatorSearchAdd($subscriptionId, $prefix, $selection)`.
`CustomerAccess` enforces the operator's actual authentication and CSRF mechanism.

Lifecycle validation stores canonical selection through `OrderingPlatform`.
Checkout rechecks relevant items, rejects duplicate numbers and returns private
receipts. Persist each with its actual created order line, never from browser data.
Store the originating cart-item identity as an immutable authoritative order
relationship, separate from the receipt. The ordering adapter must independently
verify that relationship and supply `originating_item_id`; verification compares
it to receipt `resource_id`. Missing/unverified mappings and receipt source
mismatches fail before carrier transport or number mutation, even if a fresh
offer would otherwise be available. The relationship survives cart removal and
reordering; never reconstruct it from a receipt, POST field or current cart index.
At the authoritative fulfilment stage, the ordering adapter holds its guard and
provides the saved line. Billing and provisioning preflight precede a fresh
availability check; confirmed allocation/activation precedes billing reconciliation.
Unknown/partial outcomes require durable recording and operator review, not retries.

## Search And Evidence

- Three coloured 2-7-digit OR terms match only subscriber digits after the prefix.
  Invalid and duplicate fields remain visibly invalid and disable Search.
- Every carrier batch is checked against every active term, even if the carrier
  ignores the requested mask. Cached browse offers seed results immediately.
- An explicit search click makes at most six sequential, round-robin requests.
  With sufficient cached matches, only explicit Find more requests more offers.
- Search/Stop/Clear, Refresh, local paging in chunks of 20, selected later-page
  visibility, sharp equal horizontal overlaps and three recommendations remain.
- Edits, paging and Clear do not request availability; no polling or retries exist.
  Session storage retains location only. Location changes clear selection.

Verification requires a fresh successful broad availability read. A returned offer
is accepted; otherwise prior scoped server evidence no older than 30 minutes may
retain a progressive-search offer outside the broad first 20. Future, forged,
expired and foreign-scope evidence is rejected. Checkout preserves `offered_at`
and `verified_at`; verification cannot renew an old carrier-offer timestamp.
Session evidence is capped at 5,000 offers per scope and 16 scopes.

Offer evidence is **not a carrier reservation**. A durable evidence adapter may
implement operator-specific storage, but actual reservation/duplicate allocation
guarantees must be enforced by authoritative provisioning. Do not invent carrier
reservation APIs or infer reservation from HTTP 200. Delayed orders without valid
evidence need a fresh offer or a separately verified operator reservation workflow.

## Protection And Audits

One shared lock is held through every availability call, with five seconds between
starts and a 15-second bound on queue/spacing waits. Per-session transport attempts
are limited to 30 per rolling 60 seconds. Trusted order rechecks retain the shared
limiter; authoritative order/idempotency guards prevent repeated fulfilment.
Nonce lifetime is 24 hours; future timestamps fail. Browser calls require POST,
nonce and owned catalog context, with configured origin/referrer checks. Enforce
web-server body limits too; the endpoint rejects declared bodies above 4 KiB.
Client Stop cannot retract a carrier call already sent; proxy disconnect detection
may be late. No automatic retry compensates for busy, failure or uncertain outcomes.

Daily private CSV/JSONL records contain verified account/product/subscription or
cart attribution, connecting peer IP, action, prefix, counts, carrier status,
separate queue/carrier/elapsed timing and safe failure reasons. `client_id` and
`service_id` are operational column names carrying opaque operator identifiers.
No credentials, nonce, raw session ID, full number, search digits or raw bodies
are logged. Denied context is explicitly unverified; pre-nonce denials belong in
security/access logs. Transport invocation is not proof of remote receipt or
allocation. Audit files are individually locked, not an atomic CSV/JSONL pair.

Approve personal-data access controls, retention, closed-file rotation, backups,
deletion and monitoring before deployment. Log failures warn generically; do not
retry carrier operations to recreate an audit record. Never rotate/delete the
live limiter file. Separate hosts require a shared gateway or a verified distributed
limiter; independent local files do not coordinate traffic.

## Local Verification

```sh
for contract in examples/operator-number-search/tests/*_contract.php; do
    php -d xdebug.mode=off "$contract" || exit 1
done
node examples/operator-number-search/tests/search_contract.cjs
```

Tests use synthetic adapters, virtual clocks and real separate-process locks,
never carrier requests. The JavaScript suite executes the real engine/binder in
a simulated DOM, not a genuine browser. [tests/demo.html](tests/demo.html) opens
directly and intercepts availability with local fixtures; no server is needed.
Browser screenshots, accessibility, real controller persistence and proxy tests
remain staging gates. Real operator adapter configuration, ordering/subscription
lifecycle and production provisioning/billing verification, shared Magrathea
request-limiting coverage across all availability consumers, controlled staging
acceptance and final production deployment approval are still outstanding.
See [REVIEW.md](REVIEW.md); `0.7.0-dev` does not establish production readiness.