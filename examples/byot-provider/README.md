# DOMAINTAINS BYOT provider reference

This is an **independent telecoms operator's HTTPS activation endpoint**, not a FreePBX module. It is designed to be bundled under `examples/byot-provider/` in the public DOMAINTAINS repository. It has no dependencies on any particular provider's billing, DNS, SIP routing or hosting infrastructure.

The **0.5.0-dev** BYOT foundation established persistent neutral identities while retaining existing identities. The current **0.6.0-dev** release adds provider-neutral emergency registration. This standalone application implements the compatible flat activation protocol plus billing/service linking, numbering, reachability, optional DNS and two independently replaceable SIP edges. The default infrastructure adapters are **simulations**, not vendor connectors. They cannot establish or prove live telephone connectivity.

## Directory structure

- `public/activate.php`: public HTTPS POST endpoint; point the web server's document root here, not at the entire example directory.
- `src/ActivationProvider.php`: request validation, Ed25519 verification, service authorisation, response contract.
- `src/ReplayStore.php`: atomic, persistent replay prevention and first-use public-key binding.
- `src/Contracts.php`: replaceable adapter interfaces.
- `src/LocalRegistry.php`: local billing, customer/PBX/voice links, entitlements and expiring hashed credentials.
- `src/Orchestrator.php`: durable per-service steps, verification and retry.
- `src/Simulation.php`: numbered allocations, authorised destination, optional DNS and independently stored inbound/outbound edges.
- `src/Runtime.php`: application composition with typed adapter injection.
- `src/StateStore.php`: private transactional filesystem state.
- `config.example.php`: synthetic billing, explicit reachability and edge calling policy.
- `bin/operator.php`: trusted operator order/link, rotation, status and revocation commands.
- `bin/generate-token.php`: generates a high-entropy activation token and its SHA-256 hash.
- `tests/contract.php`: self-contained synthetic protocol and security tests.

## Requirements

PHP 8.2+ with Sodium, POSIX, JSON, a local filesystem supporting `flock`/atomic rename and an HTTPS PHP web server. Run as an unprivileged dedicated account. No FreePBX runtime, commercial system, Composer package or database is required by this application. The module retains PHP 7-compatible syntax independently.

## Setup

1. On an isolated operator test host, point the HTTPS document root to `public/` only. Do not expose `src`, `bin`, configuration or state. Nothing here performs deployment automatically.
2. Prepare `config.example.php` outside the web root (for example `/etc/domaintains-byot/config.php`), mode `0600` or `0400`, readable only by the PHP service account or authorised administrator. Use a canonical absolute path and no symlinks.
3. Set the PHP worker's environment variable `DOMAINTAINS_BYOT_CONFIG` to the absolute private configuration file path. Ensure the web server actually passes this variable to PHP-FPM.
4. Set `private_state_dir` to a canonical absolute path outside the web root, owned by the dedicated PHP account (`0700`). Files are `0600`; root runtime, symlinks, foreign owners and malformed stores fail closed. Use persistent local storage, trusted parents and a single authorised Unix account for HTTP and CLI. Do not share it with unrelated applications.
5. On a **fresh disposable PBX**, obtain `fwconsole domaintains identity`. Substitute that exact ID for every `replace-with-local-pbx-identity` entry, including the billing PBX's `service`. Existing activations must retain their old ID, signing key, endpoint and ownership.
6. Configure trusted account/PBX/voice associations, assigned numbers, explicit channel entitlements, edge hosts, carrier policy and PBX destination. The reserved `.example` hosts and fictitious numbers cannot route live traffic. A destination is operator-authorised, not inferred from arbitrary DNS or caller-supplied IPs.
7. Run one of the operator workflows below and deliver a newly displayed token privately. First valid use pins the PBX public key atomically, before external operations. A key cannot be rebound by token rotation or used for a second service. An optional destination `public_key` pin can require a pre-approved key. Back up replay/key-binding state; never delete it to fix an activation.
8. Run all tests below. Configure HTTPS with a valid certificate, no request-body logging, trusted reverse-proxy HTTPS signalling and edge/proxy throttling. The entry point never trusts client forwarding headers. Verify GET/HEAD are rejected (405), non-JSON is rejected (415), plain HTTP is rejected and bodies above 16 KiB fail.
9. Configure `fwconsole domaintains endpoint https://provision.example/activate.php` on the disposable PBX, supply the explicit local policy below, then use the hidden `fwconsole domaintains activate` prompt. This can create/modify PBX objects. Do not run it on existing provisioned PBXs as an integration test.

## Emergency Management

The provider-neutral emergency capability includes an authenticated customer
interface, replaceable registration/authority/customer-authentication adapters,
private JSON/body-condition mapping example, current assignment checks, explicit confirmation,
one-use form intents and persistent provider/account/number/operation quotas. Addresses and registration
records remain provider-authoritative and are never persisted here. It is
disabled until an operator installs authenticated customer integration and
configures its provider and supplies an authoritative assignment adapter;
the non-locking `LocalBilling` reference is rejected for emergency authority.
See [EMERGENCY.md](EMERGENCY.md) for the contracts,
working composition examples, security requirements and test commands. This
does not modify FreePBX Emergency Caller IDs or prove live carrier acceptance.

## Operator Workflows

The following commands run from this directory as the unprivileged provider account with `DOMAINTAINS_BYOT_CONFIG` set. They are local trusted administration, **not** public customer endpoints. A web/customer integration must derive account identity from its authenticated session, check administrator authority, use authenticated transport with replay/idempotency protection, and call the registry adapter; never trust an account ID supplied by an unauthenticated client.

```sh
php bin/operator.php order account-01 pbx-01 PBX_ID voice-01,voice-02
php bin/operator.php customer-link account-01 pbx-01 PBX_ID voice-01,voice-02
php bin/operator.php status PBX_ID
php bin/operator.php operation PBX_ID
php bin/operator.php rotate account-01 pbx-01 PBX_ID voice-01,voice-02
php bin/operator.php set-status PBX_ID revoked
```

Replace `PBX_ID` with the actual local ID. `order` verifies ownership/status, prepares allocation, number activation and inbound-edge carrier delivery, verifies it, then issues a credential. A failed order issues no new credential and retains its progress. `customer-link` validates the same authoritative associations and aggregates distinct voice services before activation. Number lists are deduplicated; repeated service IDs never add channels twice. Foreign/inactive/conflicting links fail. A valid existing credential is reused by retaining its hash and expiry, returning `token: null`; lost, expired or revoked credentials require explicit `rotate`. New tokens have 256 bits of entropy, expire after 24 hours and appear once, only for private delivery. Do not capture terminal output in logs. `bin/generate-token.php` is a utility only; generating a random token alone does not authorise anything in the registry.

`set-status` supports active, paused, suspended, revoked and cancelled. Revisions increase on linking, rotation and status changes. Revoked/cancelled credentials cannot be restored with `active`; rotate deliberately. Rotation changes credentials, not signing identity. Replacing a signing key requires a separately authenticated, reviewed operator recovery process; no unsafe pin-reset command is supplied. These provider controls deny future activation but do not push changes to an offline PBX or tear down its objects.

## Explicit Routing

There is no neutral national, emergency or catch-all default. An administrator must choose and validate calling permissions on the PBX **and** outbound edge. This synthetic PBX policy matches only the documentation number block and is not a real dialling recommendation:

```sh
fwconsole domaintains routing '[{"prepend_digits":"","match_pattern_prefix":"","match_pattern_pass":"12025550XXX","match_cid":""}]'
```

Set the edge `policies` to the corresponding authorised carrier/patterns. The reference requires one inbound and one outbound edge, explicit context, IP authorisation and no registration; it rejects unsupported trust policies. Real IP trust must use an operator-verified address/reachability mechanism, suitable ACLs and anti-spoofing controls. The module does not manage Firewall. Changing protected local patterns after activation or an existing managed route requires an explicit ownership-reviewed change, not silent route replacement. Existing legacy routing remains unchanged.

The provider must create and maintain its own actual SIP routing, support, number assignments, call permissions and any necessary provider-side infrastructure. Returning trunk details **does not** provision an SBC, authorise call traffic or configure cloud firewalls.

### Security and operations

- HTTPS is required; TLS verification is performed by the DOMAINTAINS client. `public/activate.php` checks the PHP `HTTPS` server variable rather than trusting user-supplied forwarding headers. Configure your trusted reverse proxy and PHP-FPM correctly.
- The endpoint accepts **POST JSON only**, up to 16 KiB, and never logs activation tokens or private keys.
- Signatures use Ed25519 over **exactly** the canonical claim key order `token`, `public_key`, `timestamp`, `nonce`, `service`, encoded with `JSON_UNESCAPED_SLASHES`.
- The registry checks current ownership, status, expiry, revision and authoritative entitlement on every activation and between orchestration steps. Local configuration must be updated to represent billing changes; real billing adapters must query their authoritative system rather than treating this fixture as live commerce.
- Reference validation matches the 0.4.1-dev security limits: 64 trunks, 10,000 numbers, up to 32 digits per number, up to 1,024 bytes per descriptive field and a 1 MiB JSON response cap. `sip_host` must be a valid DNS name, IPv4 or IPv6 literal, rejecting control characters and configuration delimiters.
- A timestamp outside the configured acceptance window is rejected. Nonces are recorded durably **before** a success response. Replaying a request is rejected. A consumed nonce cannot be retried to obtain another success response.
- The service must already be explicitly authorised in the private provider configuration. A valid cryptographic signature alone does **not** confer service entitlement.
- Keys become bound to service IDs on first authorised activation unless pre-pinned. First-use binding assumes the operator issues sufficiently random tokens and delivers them securely. Never reuse one token across unrelated services.
- The replay and key-binding state is independent of a local FreePBX's state and must be included in operator backups. Do not erase it during ordinary service updates. For a large deployment, consider an audited transactional database implementation.
- The application has a global 600-request/60-second limiter, including invalid requests, and a 10,000-nonce cap. Also use reverse-proxy per-client limits, body/time limits, logs omitting request bodies/tokens, monitoring and disk-space alerts. This filesystem reference is not a distributed production control plane.
- Deactivation in DOMAINTAINS is a **local unlink** and is not a remote billing cancellation or provider-route teardown request. The operator must implement its own service withdrawal process.

## Wire contract

POST JSON:

```json
{
  "action": "activate",
  "claim": {
    "token": "<secret token>",
    "public_key": "<base64 Ed25519 public key>",
    "timestamp": 1800000000,
    "nonce": "<base64 of 24 random bytes>",
    "service": "example-service-01"
  },
  "signature": "<base64 detached Ed25519 signature>"
}
```

The signature covers the canonical JSON encoding of the **claim object only**, in the key order shown. The public key is asserted by the request but trusted only after the token entitlement and optional pre-pinning checks succeed.

Successful HTTP 200 JSON, with fields **at the top level** (not in a nested `data` or `result` object):

```json
{
  "ok": true,
  "message": "DOMAINTAINS activated",
  "service": "example-service-01",
  "profile": "Example BYOT trunk service",
  "domaintains_hostname": "pbx.example",
  "numbers": ["12025550101"],
  "max_channels": 10,
  "trunks": [
    {"role": "inbound", "sip_host": "inbound.example", "sip_port": 5060},
    {"role": "outbound", "sip_host": "outbound.example", "sip_port": 5060}
  ]
}
```

The actual values must be derived from the operator's entitlements, not blindly returned from the request. `max_channels` and `sip_port` are JSON integers; `numbers` is an array of unique digit strings. Both inbound and outbound roles are permitted by the current module; a non-empty trunk list is required.

## Module compatibility prerequisites

The provider and module accept service IDs with ASCII letters, digits, dots, underscores and hyphens (1–128 characters; first character alphanumeric). A new non-legacy PBX persists `pbx-` plus 128 random bits. A retained service takes precedence over hostname. Damaged or conflicting identity state fails closed. Identity and explicit routing survive local unlink. The canonical signing order and flat schema are unchanged; no lifecycle event protocol is introduced.

## Safe tests

From the repository root, run:

```sh
php -d extension=sodium examples/byot-provider/tests/contract.php
php -d extension=sodium examples/byot-provider/tests/local_contract.php
php -d extension=sodium examples/byot-provider/tests/orchestration_contract.php
php -d extension=sodium examples/byot-provider/tests/concurrency_contract.php
php -d extension=sodium tests/byot_contract.php
```

Omit `-d extension=sodium` when Sodium is already loaded. All tests use temporary local state and synthetic keys. The concurrency suite requires `proc_open` and starts independent PHP processes. The module test uses its actual key loader, Ed25519 claim, response validator and reconciliation against FreePBX API doubles; only transport is in-process. It is not an HTTPS, native FreePBX or carrier integration trial. A complete disposable FreePBX **16 and 17** activation/upgrade, terminal/privilege, native route/reload and call test remains unrun and mandatory before operational acceptance.

## Recovery And Adapter Replacement

Each activation locks its service, records step intent before effects, verifies numbering/DNS and both edges, and saves its outcome. Repeat activation rechecks all prerequisites but applies edges only when changed. A crash or failure may leave owned partial resources: inspect `operation`, fix the reported prerequisite using private monitoring, and retry with the same token/key and a **new** signed nonce. Never erase keys, replay state, ownership or operation files. Registry and operation writes are atomic and flushed; backup the complete private directory under an appropriate quiesced/consistent snapshot procedure. Filesystem locks require one host/local filesystem; use transactional database locks for multi-host workers. Audits retain the most recent 128 events; export redacted events securely for long-term retention. Disk exhaustion fails closed and requires operator repair.

Edge simulations store explicit owners, specs and fingerprints; matching objects stay unchanged, unmanaged/drifted objects conflict, and stale removal requires verified ownership. Number destinations removed from entitlement are cleared, but commercial allocations are not silently released. Neither local unlink nor provider revocation means remote teardown.

Implement the interfaces in `src/Contracts.php` and inject adapters into `Runtime` (billing, numbering, endpoint resolver, independent inbound/outbound edges, optional DNS and registry). Adapt the private application composition for your implementations; never put secrets or vendor code into public configuration examples. Preserve account/PBX/service validation, atomic key binding, locking, idempotency and verification. Real connectors must use documented management APIs over authenticated least-privilege transport with bounded requests, deadlines and responses. If choosing SSH, use restricted forced commands and pre-pinned host keys; do not construct shell commands from provisioning data. A FreePBX SBC connector is intentionally not fabricated without a tested public API/ownership model.

Return `status` as `simulated`, `configured`, `verified-config` or `call-tested`; verification methods must independently inspect actual persisted/remote resources and return `verified: true` only on a match. `ok` or a successful configuration call alone is not verification. Optional edge `stale` lists identify only proven owned native resources. `removeOwned` must revalidate ownership/fingerprints before deletion. Reference operation results preserve `simulated` and always record `call_tested: false`; the flat PBX response is issued only after configured prerequisites verify, not as proof of a call.

Provider lifecycle authority must retain monotonic revisions for status, credential rotation, key-rotation recovery and entitlement changes even while a PBX is offline. The future PBX delivery/acknowledgement and selective remote teardown contract is not implemented or chosen. Do not invent an event transport or interpret local Deactivate as a remote cancellation.
