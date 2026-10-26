# Provider-Neutral Emergency Registration

Introduced in **0.6.0-dev**, building on the **0.5.0-dev** BYOT foundation.

Emergency registration belongs to the operator's provider infrastructure, not
the FreePBX module. `public/emergency.php` supplies an authenticated customer
interface; `public/emergency-api.php` handles its same-origin JSON requests.
No emergency addresses, registration records or status mirrors are persisted
by DOMAINTAINS. Every retrieval and status check goes to the provider. Existing
BYOT identities, activation, keys, ownership and Firewall behaviour are unchanged.

## Enable An Independent Integration

Emergency management is disabled until a private application factory and
authenticated customer integration are configured. There is no demonstration
login, shared activation-token login or browser-supplied account selector.

1. Keep the standalone provider's existing HTTPS, private configuration, state
   ownership and unprivileged runtime setup. Expose only `public/`. Serve `.php`
   through PHP; never expose PHP source or configuration. Disable PHP display
   errors and request/response-body logging. Do not enable debug capture,
   analytics, third-party scripts or address logging on these endpoints.
2. Implement `CustomerAuthenticator` using the operator's customer portal.
   `TrustedSessionCustomer` is a minimal adapter for a portal that populates
   `$_SESSION['domaintains_customer']` **only after** authenticating the customer
   and establishing emergency-management permission. Its record is
   `account`, `subject` and an integer `expires_at`. These are trusted server
   values, never copied from request parameters or arbitrary forwarding headers.
   Regenerate the session ID on login/account changes, enforce logout/expiry,
   and integrate your live session revocation checks. Secure, HTTP-only,
   SameSite=Strict cookies and HTTPS are required. No login endpoint is supplied.
3. Adapt `emergency.config.example.php` outside the document root. Generate a
   private 32-byte random key, base64 encode it into `intent_key_base64`, and
   retain it across workers/restarts. Never publish the key. The integration
   derives separate HMAC keys for form intents and opaque number selectors.
4. `emergency.integration.example.php` returns an application factory using
   the existing registry, `TrustedSessionCustomer`, `MappedEmergencyAdapter`
   and an explicitly supplied `EmergencyBillingAdapter`. It refuses to start
   with `LocalBilling`: that adapter is a static entitlement reference, not an
   assignment lock. In your private configuration set
   `emergency_application` to that factory and `emergency` to your private
   settings. For example, in the operator's private PHP configuration:

```php
$configuration['emergency'] = require '/private/operator/emergency-settings.php';
$configuration['emergency']['billing_adapter'] = $authoritativeEmergencyBilling;
$configuration['emergency_application'] = require '/path/to/provider/emergency.integration.example.php';
return $configuration;
```

5. Supply current authorised number assignments alongside the existing billing
   records. The local reference uses `billing.number_assignments[NUMBER]` with
   `account`, `service`, `pbx`, `status: active`, positive integer `revision`, and optional
   integer `expires_at`. These are entitlement records, **not emergency data**.
   Advance the revision whenever customer, product/service or PBX association changes. Current
   PBX/voice service status and expiry are also checked. The reference fixture
   is only a schema example. Your authoritative adapter must read current
   entitlements under the ownership guarantee described below. Merely wrapping
   `LocalBilling` or declaring the interface without that guarantee is invalid.
6. Configure the provider's real API methods, fixed HTTPS base URL, fixed paths,
   private authentication, field mapping, explicit status mapping and documented
   scoped rate policy. All numerical quotas and response codes in the example
   are synthetic, not recommendations or carrier specifications. Replace them
   with the provider's documented limits. The `.example` endpoint is deliberately unusable
   and contains no real carrier details. Test a disposable provider account and
   authorised test numbers before making it available to customers.

The customer page discovers only the authenticated customer's active linked
service/PBX contexts and number selectors. Altering any selector does not grant
access: every request resolves the actual number from trusted current records.
Activation credentials, public keys and signing identities confer no customer
emergency-management permission. Expiring an activation token is not expiring a
customer's commercial service; commercial/assignment expiry is authoritative.

Each `service` represents one DOMAINTAINS product linked to one PBX. Existing
registry uniqueness checks retain that one-product-per-PBX relationship. Voice
entitlements can supply multiple numbers; emergency authority is resolved for
each individual number, not inferred from customer membership or the whole
voice bundle. Moving one number does not transfer its siblings. The old
customer/product/PBX selector and signed forms cease to authorise it; the new
owner must obtain a fresh selector after the trusted entitlement and registry
link are updated. Registration data remains controlled by the provider. No
private billing model, product code, carrier endpoint or infrastructure is assumed.

## Adapter Contracts

`EmergencyRegistrationAdapter` defines `retrieve`, `submit`, `status`,
`supportsTypeChange`, `rateLimits` and `minimumInterval`. The last method is a
fallback retry suggestion, not a shared throttle. Results use:

```php
[
    'status' => 'awaiting_acceptance',
    'revision' => 'opaque-provider-version',
    'registration' => null,
]
```

Normalised states are `unregistered`, `received`, `awaiting_validation`,
`submitted`, `awaiting_acceptance`, `registered` and `rejected`. A provider's
receipt, HTTP 200/202 or successful upload is **not** proof of registration.
Map `registered` only from an explicit authoritative registration confirmation.
HTTP-only acknowledgement mappings cannot map to `registered` in the reference.
Use `response_rules` for provider-defined meanings that share an HTTP status:

```php
[
   'http_status' => 400,
   'operations' => ['retrieve'],
   'when' => [['path' => ['error', 'code'], 'equals' => 'NO_REGISTRATION']],
   'outcome' => 'unregistered',
]
```

All conditions must match using strict scalar equality; paths traverse decoded
JSON objects. Other outcomes are `invalid_number`, `unauthorised`, `rejected`,
`conflict` and `unavailable`. Invalid numbers produce a distinct safe 422 result;
provider authorisation failures produce 403, not an editable blank form. Unknown
errors, missing/wrong-type fields and conflicting rules fail closed. HTTP 401/403
always deny access. Missing outcomes apply only to retrieval. Legacy
`missing_http_statuses` accepts only explicitly documented unambiguous 404/410;
leave it empty when response-body conditions are needed. An unknown explicit
provider state cannot fall back to an HTTP acknowledgement.

Retrieval includes `registration` when a record exists. Canonical fields are
`title`, `forename`, `honours`, `name`, `business_suffix`, `address_line_1`,
`address_line_2`, `locality`, `region`, `postcode` and `country`. `name` is the
same provider field for surname and business name. Country uses a two-letter
uppercase code. Additional provider schemas, authentication/signatures, nested
objects, multi-location formats or non-JSON APIs require a custom adapter, not
provider-specific logic in the controller or PBX module.

Individual is detected when Title, Forename or Honours contains a value;
otherwise Business is selected. Unsupported type changes fail server-side.
Switching types clears incompatible identity fields, including the shared name
input, and resets confirmation. A Business submission with personal identity
fields, or Individual with a business suffix, is rejected before any carrier
call. Honours and Business Suffix suggestions are editable, not fixed whitelists.

Submission requires a fresh signed form intent and explicit accuracy confirmation
on both client and server. Intents bind the authenticated subject/account,
service/PBX, number assignment, provider revision, initial registration type,
expiry and random nonce. They contain no address. Accepted attempts consume the
nonce before the carrier call; refresh current provider details to obtain a new
intent. A second tab or replay cannot blindly resubmit the same intent. The
adapter must honour idempotency and provider optimistic concurrency (or an
equivalent authoritative mechanism) across **all** mutation stages. Do not
misrepresent unsupported `If-Match` headers as effective conflict protection.

`MappedEmergencyAdapter` demonstrates private field/status/HTTP mappings,
Bearer/header authentication, revision conditionals and per-stage idempotency.
It sends all canonical fields, including explicit empty incompatible fields.
Real providers that require a separate clearing/deletion operation must implement
it in their adapter and verify the result; never silently omit stale fields.
For more than one stage, set `submission_stages` and explicitly confirm
`allow_stage_burst: true` **only if** the provider's documented rate limits permit
the bounded burst (maximum eight stages). Otherwise implement a provider-specific
rate-safe adapter with explicit safe recovery, without persisting addresses here.
Every stage reserves quota separately, including stages that fail. Returned
provider revisions feed the next stage's conditional request. If the first stage
uses the available quota, later stages stop and report a partial outcome; no
sleeping, quota bypass or automatic mutation retry is performed.

`CurlEmergencyTransport` verifies TLS peer/hostname, disallows redirects and
non-HTTPS protocols, caps responses at 64 KiB and sets 5-second connection and
20-second total request deadlines. Browser URLs, credentials, numbers and HTTP
methods cannot override private adapter configuration. Keep API credentials
account-scoped and least-privileged. Custom authentication (OAuth, mTLS, request
signing, etc.) belongs in a private transport/adapter using equivalent safeguards.

## Isolation And Concurrent Reassignment

`EmergencyAuthority.withLease` checks the customer, current active service,
PBX ownership and linked voice services, current number assignment and expiry.
Opaque number IDs bind assignment/service revisions. They are selectors, not
capabilities. Every adapter request must use `EmergencyLease.request`, which
rechecks ownership immediately before and after the carrier call. The lease is
invalid outside its callback and must never be queued or reused asynchronously.

The local authority holds the registry lock and
`EmergencyBillingAdapter.withEmergencyAssignments` for the complete synchronous
operation. Both that method and `emergencyAssignment` receive the server-derived
account, service/product and PBX; the latter also receives the trusted number.
The constructor requires this explicit interface, and the composition factory
requires `emergency.billing_adapter`; neither accepts `LocalBilling` implicitly.
A real implementation **must** provide serialisable assignment
locking or a provider-enforced conditional ownership lease, and return a current
revision from `emergencyAssignment`. All entitlement reassignment/revocation
writers must participate in that mechanism, including individual number moves
and changes to customer, product, PBX, voice entitlement or commercial status.
Acquire registry then assignment locks in that order when both are needed; never
invert the order or release the assignment guarantee during a provider request.
With conditional authorisation instead of a lock, the custom authority/adapter
must bind the provider's actual assignment version and account/product/PBX/number
to **each** network operation so the provider rejects reassigned targets atomically.
A preflight check followed by an unconditional request does not meet the contract.
The generic mapped adapter is suitable only with genuine assignment locking and
appropriately scoped provider credentials; it cannot invent conditional ownership
support from a revision string or a configuration flag. Check actual account/PBX/number
scope at the carrier API boundary too; a broad API credential plus a stale local
lookup is insufficient. A signature or activation token cannot substitute for
these checks. Account-supplied IDs must never be used to construct unchecked API
queries. Reject expired/detached/reassigned resources before any carrier access.

No generic software can retract a request already in flight when a provider
independently reassigns its target. Use authoritative locks/conditional account
scope to prevent that race. If reassignment is detected, no subsequent stage is
sent and no returned personal data is disclosed. This is tested, but real
provider atomicity requires live integration validation. Adapters that cannot
provide the required assignment guarantee must remain disabled.

## Scoped Provider Quotas

`rateLimits()` returns a stable private provider/credential-pool identifier and
`rules`. Each rule declares `scope` (`provider`, `account` or `number`), an
`operations` list (`retrieve`, `submit`, `status`), integer `limit` and rolling
`window` in seconds. Every operation must be covered. Multiple rules compose:
all must have capacity before any is consumed. Use a multi-operation rule for
an actual shared quota and separate rules for independent operation limits.
Provider-wide quotas intentionally affect all customers sharing that pool;
account and number rules do not throttle unrelated scopes. Numbers use a stable
private HMAC independent of product/PBX reassignment, rather than a browser ID.

The sample combines provider/account aggregate budgets with different per-number
read, write and status windows. A permitted retrieval leaves submission available
unless a documented shared quota is exhausted. Responses expose only per-operation
wait durations, not account identifiers, bucket keys or another customer's usage.
The browser disables each operation independently and never polls automatically.

`emergency-control.json` stores only hashed quota bucket identifiers, bounded
request timestamps and expiring consumed random intent nonces, not addresses or
registration status. Reservations and nonce consumption are atomic, persisted
before each carrier request and shared across sessions, restarts and workers.
The quota lock is released before network I/O; the assignment/registry ownership
locks remain held. This reference serialises registry operations for safety;
high-throughput integrations need equivalent transactional ownership guarantees,
not removal of the locks. All workers sharing a pool must use the same store,
keys and canonical policy. Changing the policy or keys changes its bucket IDs;
coordinate reconfiguration conservatively against existing provider usage.
The obsolete global `next_provider_at` is removed on the next successful reservation.
Use a shared transactional limiter for multi-host installations and stricter
reverse-proxy customer/IP request limits against abusive traffic. The local
filesystem store is single-host only and bounds quota metadata growth.

Malformed replies and raw provider exceptions are never returned to customers.
Submission and status responses contain no address; only an authorised fresh
retrieval returns canonical address fields. Uncertain/partial mutations advise
checking current provider state, not automatic replay. The UI clears hidden
address fields on loading, retrieval failure, number/context changes, submission
completion and page-history restoration. Responses use no-store, restrictive CSP,
no-referrer and framing protection. Do not add request/response body logging at
the proxy, PHP worker, transport, APM or application layer.

## Caller ID And Multiple Locations

This capability registers provider information; it does not select or configure
FreePBX Emergency Caller IDs. A future extension-specific Caller ID or location
selector must resolve an authorised number through this same current entitlement
boundary, select only provider-owned location references, and independently
verify the location's explicit registered state and suitability for that Caller
ID. Bind any selected location/revision into the signed intent and provider
conditional request. Do not treat an address, a supplied Caller ID, or another
location registered for the same customer as authorisation. Multiple provider
locations must remain provider-authoritative, not a local location database.

## Verification

From the repository root:

```sh
php -d extension=sodium examples/byot-provider/tests/emergency_contract.php
NODE_PATH=/path/to/test-only/node_modules node examples/byot-provider/tests/emergency_browser.cjs
```

The browser test requires Playwright/Chromium (with OS libraries) and mocks only
synthetic API responses. It checks desktop/mobile loading privacy, confirmation,
type switching, retry and status presentation. It does not test live login or
carrier acceptance. The PHP contract tests real server-side controller/authority
checks, actual customer/PBX/service fixtures, detached/reassigned/revoked/expired
resources, forged parameters/CSRF, multi-stage reassignment, replay/rate limits,
type changes, ambiguous HTTP 400 replies, malformed/partial replies and
address-free persisted metadata. Real worker processes cover same-number races,
independent customers and numbers, aggregate quotas and assignment writers blocked
through the provider request. Mutable-adapter tests additionally simulate an
external reassignment between stages and check suppression of later calls and data.

Run every existing module/provider suite as well. Operator customer-session
integration/revocation, real provider schema/authentication/rate limits, atomic
assignment guarantees, registration validation/acceptance and live FreePBX
emergency Caller ID/call routing still require controlled testing. No live
carrier acceptance or emergency test call is established by these simulations.
Never place an emergency call for testing without explicit provider and relevant
emergency-service authorisation and an agreed test procedure.