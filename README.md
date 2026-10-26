# DOMAINTAINS

⚠️ Not currently suitable for use in production

DOMAINTAINS is a community module for FreePBX 16 and 17. It lets an authorised
communications provider deliver and maintain the FreePBX configuration
associated with a service.

## What DOMAINTAINS Does

The module accepts service configuration authorised by a compatible provisioning
service, applies it through FreePBX APIs, and verifies the resulting local
state. It can create, verify and repair configuration it owns. Assigned
incoming numbers initially use **DOMAINTAINS Test**, which answers with
confirmation tones and hangs up.

## Why Has My Provider Asked Me to Install This?

DOMAINTAINS is the PBX-side component that lets your communications provider
install and maintain the specific FreePBX configuration associated with your
service. Without the module, that configuration would generally need to be
entered and maintained manually.

## What Will It Change on My PBX?

DOMAINTAINS may manage its own:

- inbound and outbound SIP trunks;
- inbound routes for provider-authorised numbers;
- outbound routing where applicable;
- provider-authorised channel limits on managed trunks;
- FreePBX reload notifications when changes require them.

Newly created and verified objects are recorded in a separate ownership ledger.
Pre-existing objects may be referenced or reused only when they already exactly
match the required state. Reuse is not ownership: an unowned object is never
updated, normalized, deleted/recreated or otherwise mutated. Safe legacy trunks
requiring changes fail closed with an explicit ownership-adoption instruction.
Conflicting or ambiguous objects are not adopted. See **Durable Object Ownership**.

## What Happens When I Activate It?

In plain terms:

1. The administrator enters an activation token.
2. The PBX creates or reuses its persistent signing identity.
3. It securely asks the compatible provisioning service for configuration
   authorised for this PBX service.
4. DOMAINTAINS applies that configuration through FreePBX APIs.
5. It verifies the result before reporting successful provisioning.

## What Happens Later?

When Activate is run again with the existing activation authorization,
DOMAINTAINS refreshes the provider-authorised configuration and reconciles
module-owned objects only when their current configuration matches recorded
ownership evidence. Unexpected administrator changes fail closed; the module does
not overwrite them or silently recapture their fingerprints. A provider-authorised
change, such as a new channel limit, can be reconciled when the current owned
trunk still matches its recorded fingerprint.

This applies to DOMAINTAINS-owned objects; it does not mean the module
arbitrarily rewrites unrelated FreePBX configuration. Conflicts with other
configuration are not silently taken over.

## What DOMAINTAINS Does Not Do

DOMAINTAINS is not a remote shell, remote desktop, general-purpose PBX
administration agent, billing system, carrier, DNS service or replacement
FreePBX distribution. Its public implementation is scoped to activation and the
reconciliation of its own service-related FreePBX configuration.

## Administrator Security Overview

- The PBX uses a persistent cryptographic signing identity for activation.
- Activation claims are signed and sent over HTTPS.
- The activation token is not retained in plaintext after successful
  activation.
- Provider-authorised state is validated before it is saved and applied.
- Local root access ultimately controls the PBX and can change its local
  configuration.
- For that reason, commercial entitlement and upstream service limits must
  remain authoritative on the provider side.

Local activation state and the persistent signing key are kept in protected
Asterisk runtime storage. The directory is mode `0700`, and sensitive files are
mode `0600`. Root `fwconsole` operations normalise ownership to the Asterisk
runtime account so the FreePBX web runtime can read the same state. Atomic writes
preserve these ownership and permission protections.

The module requires PHP sodium and HTTPS support. In **Connectivity > DOMAINTAINS**,
save the complete HTTPS provisioning endpoint supplied by your operator, then
enter the activation token and select **Activate**. There is no default endpoint.
The CLI supports scriptable endpoint configuration and interactive activation:

```sh
fwconsole domaintains endpoint https://control.example.invalid/activate
fwconsole domaintains activate
```

The command securely prompts for the token without displaying it. Do not place
the token in the command itself. Check activation status with:

```sh
fwconsole domaintains status
fwconsole domaintains status --json
```

### Deactivate or Reactivate

To unlink local activation without removing the PBX signing identity, trunks,
routes, numbers or other provisioned configuration:

```sh
fwconsole domaintains deactivate
```

Deactivation removes only the retained activation state. The separately stored
provisioning endpoint and ownership ledger also survive. Status then reports
`unprovisioned`; existing PBX configuration remains and calls may still work.
It does not cancel a provider-side service or revoke upstream authorization.

After Deactivate, use Activate to link the PBX again. Reactivate is only
available while a valid retained activation authorization exists and is used to
deliberately replace that authorization.

To deliberately replace the activation authorization with a new token while
keeping the existing signing identity:

```sh
fwconsole domaintains reactivate
```

Reactivation asks for confirmation and then securely prompts for the new token.
It validates the provider response and reconciles configuration through the same
path as activation. Ordinary `activate` still refuses a different token while
retained activation state exists. Failed provider authorization leaves the
previous state intact; local reconciliation failures retain the newly authorized
state as pending for retry.

Both lifecycle commands require interactive confirmation and refuse
`--no-interaction`. The GUI offers confirmed Deactivate and Reactivate controls
for valid retained activation state. Damaged or incompatible state is rejected,
not silently discarded. These operations do not create a new signing identity.

# For Developers and Service Providers

This repository contains the PBX module and an independent BYOT reference
provider under [examples/byot-provider](examples/byot-provider/README.md).
A compatible operator provisioning service is required for activation. The
provider has complete local simulations and replaceable integration contracts,
not commercial carrier connectors or an operational telephone network.

The project is under active development. The current module version is
`0.6.0-dev`.

## 0.6.0-dev Emergency Registration

Building on the committed 0.5.0-dev BYOT foundation, this release adds
provider-neutral emergency registration to the operator-side reference:
retrieval, submission/update, explicit provider status, Individual/Business forms,
current customer/service/PBX/number isolation, confirmation, replay protection and
server-side rate limiting. There is no local emergency address database, no
activation-token customer login and no automatic emergency Caller ID change.
The integration is disabled until customer authentication and the provider
adapter are configured. See the [emergency operator guide](examples/byot-provider/EMERGENCY.md).

## 0.5.0-dev BYOT Implementation

New non-legacy installations persist an unpredictable `pbx-` identity separately
from hostname, activation and signing state. Discover it using
`fwconsole domaintains identity` or the CSRF-protected Show PBX identity action.
Retained service identities take precedence; existing `my-########` identities,
signing keys, ownership, fingerprints and native IDs are not migrated.

Neutral outbound routing has no built-in dialling policy. Before activation,
the administrator must supply an explicit pattern array using
`fwconsole domaintains routing '<JSON array>'`; see the provider guide for a
synthetic example. Legacy installations retain their exact existing policy.
Neither command modifies Firewall configuration. Local Deactivate preserves
both identity and routing policy as well as the original protected stores.

The standalone PHP 8.2+ provider implements order-driven preparation and
customer-initiated linking, multi-service entitlement aggregation, expiring
hashed credentials, rotation/revocation, atomic key binding, replay protection,
throttling, per-service orchestration and independently verified numbering,
optional DNS and separate SIP edges. Its deterministic adapters are simulations.
Real connectors implement the same interfaces; no private operator code is
included. See the [operator guide](examples/byot-provider/README.md).

Actual module Ed25519 requests traverse the complete reference runtime and
actual module validator/reconciliation in tests. Adapter failures, safe retry,
owned stale removal, unowned conflicts and real cross-process races are tested.
No new live FreePBX 16/17, carrier or test-call trials have been performed.
Those remain mandatory controlled acceptance gates before operational use.
There is no remote PBX teardown or lifecycle event transport.

## 0.4.1-dev Security Patch

This patch hardens the 0.4.0 development baseline without adding features or
changing state formats. It validates SIP DNS names and IPv4/IPv6 addresses,
rejects configuration-injection characters, brackets native IPv6 SIP addresses,
aborts provisioning responses above 1 MiB, and enforces documented entitlement
counts and string lengths. Root DOMAINTAINS commands switch permanently to the
Asterisk account before module access, and storage helpers refuse effective-root
access. Signing keys and correctly owned activation, endpoint and ownership
records require no migration. Local Deactivate and Firewall behaviour are unchanged.

The preceding baseline has been tested on a provisioned FreePBX 17 installation
with six adopted objects. That does not constitute live validation of this
security patch; its privilege boundary and native IPv6 behaviour still require
controlled FreePBX 16/17 integration testing. This is not a production release.
BYOT was outside the scope of this historical security patch; its implementation
is described in the 0.5.0-dev section above.

## 0.4.0-dev Milestone

This architectural milestone established the baseline for controlled FreePBX
testing. Its scope is retained below as historical context, not as new features
in the security patch.

Implemented in this release:

- An administrator-configured, provider-neutral complete HTTPS provisioning
  endpoint, replacing the hard-coded service with no default or fallback.
- A durable ownership ledger using native FreePBX object identifiers, with
  ownership recorded only after creation has been verified.
- Explicit administrator-confirmed adoption of safely identified legacy
  configuration, without changing the adopted objects.
- Protection against unauthorised mutation or deletion of unowned objects;
  exactly matching unowned objects may be reused without becoming owned.
- Ownership fingerprint verification and interrupted-operation recovery,
  including local recovery of verified pending ownership evidence.
- Ownership-protected internal configuration teardown, with verified absence
  and durable retry handling; there is no public deletion command.
- Removal of automatic FreePBX Firewall management. Firewall trust remains the
  responsibility of the operator's provisioning infrastructure.
- Preservation of signing identity, endpoint settings, ownership state and
  existing PBX configuration across local Deactivate.
- Expanded regression coverage for endpoint configuration, ownership,
  adoption, teardown, native rollback and interrupted finalisation.

Not implemented in this release:

- Remote Rotate/Revoke or event-driven lifecycle delivery.
- Provider-neutral service identity; the existing hostname-derived
  `my-[0-9]{8}` service identity contract remains unchanged.
- Selective per-resource teardown; the internal primitive processes recorded
  owned configuration subject to its safety and reference checks.
- Authoritative lifecycle revision handling.

These exclusions describe the historical 0.4.0 release. In 0.5.0-dev neutral
identity and provider-side revisions are implemented; remote lifecycle delivery
and selective PBX teardown remain future work. No cron, polling, background
service or inbound lifecycle listener is introduced. The legacy UK policy is
unchanged and is not applied to new neutral identities.

## Architecture

The following is an illustrative provider-neutral architecture, not a
description of the maintainers' production infrastructure:

```text
Billing / Ordering System
          |
          v
Provisioning Service
          |
          +---- Number / Carrier Provider
          +---- DNS / Infrastructure
          +---- SIP Edge / SBC
          |
          v
DOMAINTAINS FreePBX Module
          |
          v
Local FreePBX Configuration
```

The module does not require a particular billing platform, number or carrier
provider, DNS provider, or SIP edge/SBC implementation. Those systems are
integrated behind the provisioning service. The service returns only the
authorised PBX configuration needed by the module.

## Provider Independence

Developers can implement a compatible provisioning service around their own
billing/order system, number provider, DNS implementation and SIP infrastructure.
For example, an integration might create an order, allocate numbers through a
provider API, prepare its own DNS and SIP edge, issue an activation token, and
then let the PBX activate against the compatible service. These are conceptual
examples; the module does not call billing, number, DNS or SIP infrastructure
APIs directly.

The provisioning endpoint is administrator-selected. It represents a compatible
operator provisioning/authorisation control service, not a SIP server. SIP-edge
and routing control are separate operator integration boundaries. Successful
local provisioning is not proof of SIP routing authority or upstream teardown.

## Provisioning Endpoint Configuration

Configure the **complete activation endpoint URL**, not a service base URL.
DOMAINTAINS sends the existing signed activation request directly to that URL;
it does not append or infer a path. The URL must use HTTPS and a valid DNS
hostname, with no embedded credentials, fragment, whitespace or malformed URL
encoding. Optional explicit ports and query strings are retained unchanged.
Do not put credentials or activation tokens in the URL.

Use the GUI's **Provisioning endpoint** setting, or scriptable CLI commands:

```sh
fwconsole domaintains endpoint https://control.example.invalid/activate --no-interaction
fwconsole domaintains endpoint --json
```

There is no provider-specific default, hidden fallback, hostname inference or
automatic migration from the former embedded URL. Existing provisioned
installations continue using their retained local configuration and status
without configuring an endpoint immediately. Activate and Reactivate require
an endpoint before provider communication can proceed.

Saving or changing the endpoint changes only where future provider operations
are sent. It does not contact the provider, unlink authorization, replace the
signing identity, change PBX objects or claim upstream service changes. Choosing
a different endpoint changes the service trusted with the next signed bootstrap
request; administrators must verify that endpoint independently. Ordinary
Activate identity protection and explicit Reactivate replacement remain intact.

Provisioning service communication is outbound HTTPS. Configuring or using the
endpoint does not add its hostname to FreePBX Firewall Internal, Local or Trusted
zones, and does not otherwise grant inbound trust. Saving or changing an
endpoint, Activate, Reactivate and teardown leave existing Firewall configuration
untouched, including entries left by earlier versions. No replacement whitelist
mechanism is used. SIP-edge/provider inbound access, if ever required, is a
separate explicit BYOT integration capability needing a documented purpose and
least-privilege design; it is not part of the provisioning endpoint contract.

## Durable Object Ownership

Runtime storage remains `/var/lib/asterisk/domaintains`, mode `0700`, with
Asterisk-owned sensitive files at `0600`, including commands invoked from root:

| Store | Purpose |
| --- | --- |
| `signing.key` | Persistent PBX signing identity. |
| `state.json` | Retained provider authorization and desired configuration; removed by local Deactivate. |
| `ownership.json` | Durable verified object identities, configuration digests, recording time and created/adopted origin. |
| `endpoint.json` | Administrator-selected provisioning endpoint. |
| `identity.json` | Persistent local service identity, independent of hostname and local unlink. |
| `routing.json` | Explicit administrator-selected neutral outbound route patterns. |
| `test-destination.json` | Module-local test destination registration. |

Endpoint and ownership writes share the activation lock, permission/ownership
normalization and atomic replacement protections. Symlinks, non-regular files
and malformed retained records fail closed rather than being overwritten.
Configuration digests do not retain plaintext SIP credentials.

Before accessing the module, root `fwconsole domaintains` commands permanently
switch supplementary groups, group ID and user ID to the existing `asterisk`
account. Failure to switch stops the command without accessing module state.
Storage helpers independently refuse effective-root access; custom root-run
callers must use the unprivileged runtime instead. No directory ownership
migration or signing-key replacement is performed. Existing correctly owned
stores remain compatible. Incorrectly root-owned legacy files require explicit
administrator inspection and repair, not automatic privileged pathname changes.

The ledger uses FreePBX's real identifiers:

- PJSIP trunk: `trunks.trunkid`, also used by the PJSIP detail API.
- Inbound route: `incoming` primary-key tuple `(extension, cidnum)`; there is no
  invented numeric DID identifier. Current managed routes have empty `cidnum`.
- Outbound route: `outbound_routes.route_id`, including its verified patterns
  and trunk references in the configuration digest.
- Test destination: the module-local registration for `domaintains-test,s,1`,
  not a fictitious FreePBX database row.

Creation is read back and verified before ownership is recorded. A crash or
storage failure before recording leaves an object unowned, never inferred owned.
An activation can be pending while ownership of earlier successful creations is
already durable. Losing the ledger does not authorize name-based reconstruction.

For legacy installations, run this while valid retained authorization and safely
identifiable existing configuration are available, including after activation
stops because a legacy trunk requires adoption before normalization:

```sh
fwconsole domaintains adopt
```

The command displays each candidate's type, actual native identifier, label,
already-owned status and current trunk settings without revealing credentials.
Safe legacy trunk candidates use the existing controlled migration rules and
unambiguous identity mapping; candidate matching is not broadened. Existing
inbound and outbound routes must still match the expected policy, and absent
objects are not proposed. Preview never mutates candidates.

Interactive confirmation defaults to no and `--no-interaction` is refused.
The preview is reverified under the module lock, then ownership of the exact
object and its current configuration is recorded without normalization. Only a
subsequent Activate/Reactivate may reconcile that now-owned trunk. Changed
candidates, ambiguous trunk mappings, conflicting routes or damaged state
prevent adoption. Repeating adoption is harmless. Unrelated objects and Firewall
entries are excluded. If already locally deactivated, Activate first to restore
retained authorization (possibly pending adoption), then review adoption explicitly.

The protected internal teardown primitive has no GUI, CLI or HTTP deletion
entry point. It checks ledger identities and recorded effective configuration,
refuses changed/ambiguous objects and unowned outbound routes referencing owned
trunks, then deletes owned outbound routes, inbound routes and trunks through
native APIs. Already-absent objects count as absent. Entries are removed only
after absence is verified; failed deletes retain them for retry. Signing identity,
endpoint and unowned objects remain. Test registration is retained while an
unowned inbound route still references it. Starting teardown marks retained
activation pending and requests a FreePBX reload notification, not upstream
service revocation.

Trunk normalization preserves the native trunk ID and existing dial options. It
replaces only that trunk's PJSIP detail rows inside the SQL transaction before
calling the native trunk writer in edit mode. It does not invoke native trunk
deletion, its deletion hooks, conversion-metadata removal or AstDB deletion.
The native writer receives the unchanged dial options. SQL rollback is not
assumed to reverse AstDB operations or external hooks: the full original trunk
fingerprint is rechecked after rollback. If restoration cannot be verified,
activation remains pending with an explicit administrator-review diagnostic;
ownership is not recaptured and unexpected configuration is not overwritten.

A verified post-edit digest is saved as `pending_fingerprint` before commit.
Recovery accepts only the recorded original or pending digest, never an arbitrary
third configuration. Protected teardown settles that evidence durably under the
module lock before deleting anything, including after local Deactivate. Adoption
preview accepts verified pending evidence without changing the ledger; confirmed
adoption revalidates the preview and settles the digest without editing native
objects. These local recovery paths do not contact the provider. Failed ledger
writes retain the evidence for retry and prevent teardown from starting deletion.

FreePBX's inspected APIs do not expose immutable object-generation tokens.
Native identity plus the verified configuration digest detects reuse with
different configuration, but cannot distinguish an externally deleted object
from an identically recreated object using the same native key. The module lock
serializes DOMAINTAINS operations, not unrelated administrator/API writes.
Real-system review must account for those limits; changed objects fail closed
and must not be force-adopted to bypass a teardown conflict.

Future last-applied authoritative revision/status must use its own protected
lifecycle store, separate from activation, ownership and endpoint configuration,
and survive local Deactivate. This release does not create speculative lifecycle
fields. Provider event transport, remote Rotate/Revoke delivery and authoritative
revision handling remain subsequent work. This machinery uses **no cron polling**
and starts no background process or inbound HTTP listener.

## Activation Model

The public activation lifecycle is:

1. A service is created in the provider's billing/order system.
2. The provider secures or assigns any required telephone numbers.
3. The provisioning service creates an activation token.
4. The token is supplied to the PBX administrator.
5. The PBX creates or loads its persistent signing identity.
6. The PBX loads its retained local service identity or creates a neutral identity (preserving valid legacy hostname identities).
7. The PBX sends a signed activation claim to the provisioning service over HTTPS.
8. The provisioning service validates the claim and authorises the activation
  for its service.
9. The service returns authorised PBX configuration.
10. DOMAINTAINS reconciles and verifies that state through local FreePBX APIs.
11. Later activation/refresh operations fetch current authorised state and
  reconcile owned objects whose current fingerprints still verify.

The module requires PHP sodium and HTTPS support. Ordinary activation preserves
the retained activation identity; explicit reactivation permits replacement
authorization using the same PBX signing identity and validated local service.
The module does not retain the activation token in plaintext after activation.

## Signed Activation Claims

The request contains an `action` of `activate`, a `claim` object, and a base64
encoded detached Ed25519 `signature`. The claim is signed in this canonical
field order:

1. `token`: the activation token entered by the administrator.
2. `public_key`: the PBX signing public key, base64 encoded.
3. `timestamp`: the claim creation time as an integer.
4. `nonce`: 24 cryptographically random bytes, base64 encoded.
5. `service`: the persistent local service identity.

The local `service` value must match `^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$` and is part of the
signature. Signing the claim binds its contents to the PBX signing identity and
helps the provisioning service validate freshness and prevent replay. The
provisioning service defines its own activation and claim-validation policy.

## Provisioning Response

A successful activation response is a JSON object with `ok: true` and the
following fields. The module validates these fields and ignores unrelated
provider metadata.

| Field | Required value |
| --- | --- |
| `service` | Exact local identity matching `^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$`; cannot be assigned or changed by the response. |
| `profile` | Non-empty provider-defined string, at most 1,024 bytes; its meaning is not interpreted by the PBX module. |
| `domaintains_hostname` | Non-empty string, at most 1,024 bytes, supplied as part of the authorised service configuration. |
| `trunks` | 1 through 64 unique trunk entries. Each entry has `role` (`inbound` or `outbound`), validated `sip_host`, and integer `sip_port` from 1 through 65535. |
| `numbers` | At most 10,000 unique numeric strings, each containing 1 through 32 digits. An empty list remains valid. |
| `max_channels` | Provider-authorised JSON integer from 1 through 500 inclusive. |

Responses are limited to 1 MiB (1,048,576 bytes), including unrelated metadata
and whitespace. The HTTPS write callback aborts the transfer before appending
an excessive chunk. Oversized transfers fail even if their received prefix is
valid JSON; truncated JSON is never accepted.

SIP hosts may be ASCII DNS hostnames (including single-label names, punycode and
an optional trailing root dot), IPv4 addresses, or bare/bracketed IPv6 addresses.
DNS labels are at most 63 bytes and names at most 253 bytes excluding the root
dot. Whitespace, controls, URI syntax, embedded ports, scoped IPv6 zone IDs and
configuration delimiters are rejected. Native settings bracket bare IPv6 for
FreePBX's SIP URI generation; the original provider host is retained for trunk
identity calculations. Existing DNS/IPv4 settings and identity hashes are unchanged.

These limits also validate retained authorization. Out-of-limit legacy records
fail closed without deleting their keys, configuration, endpoint or ownership
ledger; arrange compliant entitlements before upgrading such installations.
Invalid remote responses do not replace retained authorization or change PBX
objects. No security hardening changes local Deactivate or Firewall behaviour.

The provisioning service supplies the final `max_channels` value. DOMAINTAINS
validates and enforces it; the PBX module does not calculate commercial
entitlement. A provider might derive channel entitlement from active telephone
numbers, an account tier or another policy. That calculation belongs to the
provider, not the PBX module.

## Local Reconciliation

DOMAINTAINS treats the provider-authorised response as desired state for
module-owned configuration. Depending on the authorised state, reconciliation
can include:

- managed inbound and outbound PJSIP trunks;
- exact inbound routes for authorised numbers;
- an outbound route using authorised outbound trunks, where applicable;
- the provider-authorised channel limit on each managed trunk;
- a FreePBX reload notification and final local verification.

During refresh, a changed authorised channel limit or other managed setting is
reconciled through FreePBX APIs only for owned objects. Exactly matching unowned
objects may be reused without mutation; a safe unowned trunk needing changes
requires explicit adoption first. Unrelated trunks, routes and Firewall settings
are left alone; ambiguous ownership or administrator conflicts fail closed.

## Channel Limits

The provisioning service returns `max_channels` as an integer from 1 through
500. DOMAINTAINS applies changes to that authorised value only when the current
owned trunk matches its recorded ownership fingerprint. Unexpected manual
`maxchans` changes fail closed rather than being automatically repaired. The
module does not calculate or infer billing entitlement. Any policy used to
determine the value belongs behind the provisioning service.

## Building a Compatible Provisioning Service

A third-party service can implement the public claim and response contract
while integrating with its own systems. At a high level, an order-side flow
might look like:

```text
onOrderCreated(order):
    numbers = numberProvider.allocate(order.numberRequirements)

    token = provisioning.createActivation(
        account = order.customer,
        numbers = numbers
    )

    billing.storeActivationToken(
        order.serviceId,
        token
    )
```

An activation-side flow might look like:

```text
receiveActivationClaim()
verifySignedClaim()
validateActivationToken()
bindActivationToService()
determineAuthorisedConfiguration()
prepareProviderInfrastructure()
returnAuthorisedConfiguration()
```

This is high-level pseudocode, not a reference implementation. The provider
defines its internal services, policies and integrations. Provider, carrier
and infrastructure credentials belong on the provisioning service and must
never be distributed to the PBX.

## Billing Integration

No particular billing or ordering package is required. A billing integration
generally needs to create a provider-side service record, request or assign
numbers, obtain an activation token, associate it with the service, manage
service lifecycle changes, and communicate entitlement changes to the
provisioning service.

## Carrier Integration

Number allocation, activation and routing belong behind the provisioning
service. Number providers may offer REST or SOAP APIs, portals, SIP-related
provisioning interfaces, or other integration methods. DOMAINTAINS does not
require carrier credentials and does not call a number provider directly.

## Security Model

- The administrator supplies an activation token through the GUI or a hidden
  CLI prompt; it is not a command-line argument.
- The PBX maintains a signing identity and signs each activation claim.
- Claims include a random nonce, timestamp and locally derived service identity.
- Activation requests use HTTPS with certificate and hostname verification.
- Authorised configuration and channel entitlement come from the provisioning
  service, not from PBX-side commercial calculations.
- Provider infrastructure remains the provider's security perimeter. Local
  root access can alter a PBX, so provider-side activation, entitlement and
  upstream enforcement remain authoritative.

The former root pathname-race concern requires a process already able to modify
Asterisk-owned storage while a privileged caller accesses it. Dropping CLI
privileges and refusing root storage access remove that privileged module path;
they do not isolate state from a compromised Asterisk account. Pathname checks
and the module lock are not a defence against arbitrary same-account code or
external administrator writes. Live FreePBX 16/17 privilege-transition and
activation testing remains required; synthetic tests do not prove deployment
permissions, terminal behaviour or native integration.

### 0.4.1 Privilege Compatibility Review

Source inspection of FreePBX framework `release/16.0` and `release/17.0`
(`amp_conf/bin/fwconsole`, `FWApplication.class.php`, `Console/Moduleadmin.class.php`
and `modulefunctions.class.php`) confirms that command loading and module
installation are separate from a DOMAINTAINS command's `execute()` method.
Module Admin may need root for its own installation/ownership work; its old
privilege-drop block is commented out and is not relied upon here. DOMAINTAINS
construction, command configuration, BMO `install()` and the install script
neither drop privileges nor access module state. The installer is unaffected.

The privilege transition remains at the start of DOMAINTAINS command execution,
before status, endpoint operations, prompts, provider calls or PBX mutations.
Failures stop before module access, including partial group/user transitions.
These actions use the same runtime-accessible APIs and stores as the GUI and
do not perform unrelated root-only installation, service or ownership work.
Programmatic callers that need root work afterwards must invoke DOMAINTAINS in
a separate process; the UID transition is intentionally permanent.

Isolated tests cover all console actions and migration-free preservation of a
synthetic six-adopted-object installation. Disposable root-container tests verify
the real UID/GID transition, retained signing keys and endpoint access. The actual
vendored Symfony hidden-input and terminal checks from both branches also pass
on a root-created pseudo-terminal after the transition, without echoing the
synthetic token and with terminal mode restored. These tests do not replace a
live upgrade and activation on the provisioned PBX or exercise its other modules,
custom shutdown hooks, distribution-specific permissions or native IPv6 routing.

Provider/backend credentials for billing, carrier, DNS, and SBC management
should remain provider-side and must not be exposed to the PBX. Requirements for
any PBX-facing credentials are specific to a compatible provider integration.

## What Is Not Included

The repository includes a standalone reference provisioning backend and local
simulations, but not a commercial billing platform, carrier account, real number
inventory, DNS hosting or deployed SBC infrastructure. Operators must implement
and validate real connectors against their documented management APIs. No
specific vendor is required by the public module contract.

## Development and Testing

The repository includes PHP contract tests for the module scaffold, activation,
lifecycle/ownership and FreePBX compatibility. The activation tests use synthetic
fixtures and API doubles rather than live provider infrastructure. Run the
contracts with:

```sh
for test in tests/*_contract.php examples/byot-provider/tests/*contract.php; do php -d extension=sodium "$test" || exit 1; done
```

Run PHP syntax checks on changed PHP files and `git diff --check` before
submitting changes. Do not put real activation tokens, public service
identifiers, telephone numbers, hostnames, addresses or provider credentials in
public fixtures.

The 0.5.0-dev read-only history check found the former provider-specific
provisioning endpoint in pre-existing commits. It was not copied into the new
reference provider; history was not changed. The maintainer must review that
historical disclosure before any publication. An automated credential-pattern
scan is not proof that a repository contains no secrets. No commit, push, tag,
publication, release or deployment is part of this implementation work.

## Repository

https://github.com/kierknoby/domaintains

## Licence

GPL-3.0-or-later. See `LICENSE`.

## AI-Assisted Contributions and Disclosure

AI-assisted changes must be disclosed in each commit containing those changes.
Use a commit trailer such as:

```text
Assisted-by: TOOL_NAME:MODEL_VERSION
```

The human contributor remains responsible for the contribution. AI tools must
not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne //
[FreePBX UK](https://github.com/freepbxUK)
