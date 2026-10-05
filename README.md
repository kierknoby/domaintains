# DOMAINTAINS

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
- FreePBX Firewall configuration needed for provider access, where applicable;
- provider-authorised channel limits on managed trunks;
- FreePBX reload notifications when changes require them.

These are DOMAINTAINS-owned objects associated with the activated service.
Unrelated administrator-created trunks, routes and other configuration are not
supposed to be silently adopted or overwritten. If ownership is ambiguous or an
existing object conflicts, the module fails closed rather than guessing.

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
module-owned objects. If someone manually changes a setting on a managed object,
a later refresh may restore the authorised value. For example, a manually changed `maxchans`
limit on a DOMAINTAINS trunk can be repaired from the provider-authorised channel
limit.

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

The module requires PHP sodium and HTTPS support. To activate in FreePBX, open
**Connectivity > DOMAINTAINS**, enter the token supplied by your provider, and
select **Activate**. The CLI also supports activation:

```sh
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

Deactivation removes only the retained activation state. Status then reports
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

This repository contains the PBX-side implementation. A compatible provisioning
service is required for activation, but developers can build that service around
their own systems. The interface between the module and that service is the
public integration boundary; everything behind the service is implementation-
specific.

The project is under active development. The current module version is
`0.3.5-dev`.

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

The current activation endpoint is defined at module level rather than exposed
as a FreePBX setting. A deployment using a different compatible service must
integrate that service at the module's activation endpoint boundary.

## Activation Model

The public activation lifecycle is:

1. A service is created in the provider's billing/order system.
2. The provider secures or assigns any required telephone numbers.
3. The provisioning service creates an activation token.
4. The token is supplied to the PBX administrator.
5. The PBX creates or loads its persistent signing identity.
6. The PBX derives its local service identity from its hostname.
7. The PBX sends a signed activation claim to the provisioning service over HTTPS.
8. The provisioning service validates the claim and authorises the activation
  for its service.
9. The service returns authorised PBX configuration.
10. DOMAINTAINS reconciles and verifies that state through local FreePBX APIs.
11. Later activation/refresh operations fetch current authorised state and repair
    module-owned drift.

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
5. `service`: the local service identity derived from the PBX hostname.

The local `service` value must match `^my-[0-9]{8}$` and is part of the
signature. Signing the claim binds its contents to the PBX signing identity and
helps the provisioning service validate freshness and prevent replay. The
provisioning service defines its own activation and claim-validation policy.

## Provisioning Response

A successful activation response is a JSON object with `ok: true` and the
following fields. The module validates these fields and ignores unrelated
provider metadata.

| Field | Required value |
| --- | --- |
| `service` | Non-empty service identity matching the PBX's local identity and `^my-[0-9]{8}$`. |
| `profile` | Non-empty provider-defined string; its meaning is not interpreted by the PBX module. |
| `domaintains_hostname` | Non-empty string supplied as part of the authorised service configuration. |
| `trunks` | Non-empty list of unique trunk entries. Each entry has `role` (`inbound` or `outbound`), non-empty `sip_host`, and integer `sip_port` from 1 through 65535. |
| `numbers` | List of unique numeric strings authorised for the service. |
| `max_channels` | Provider-authorised JSON integer from 1 through 500 inclusive. |

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
- the configured activation host in the FreePBX Firewall internal zone;
- the provider-authorised channel limit on each managed trunk;
- a FreePBX reload notification and final local verification.

During refresh, a changed authorised channel limit or other managed setting is
reconciled through FreePBX APIs. Unrelated trunks and routes are left alone;
ambiguous ownership or administrator conflicts fail closed rather than being
silently replaced.

## Channel Limits

The provisioning service returns `max_channels` as an integer from 1 through
500. DOMAINTAINS applies that authorised value to its managed trunks and repairs
manual `maxchans` drift on those trunks during refresh. It does not calculate
or infer billing entitlement. Any policy used to determine the value belongs
behind the provisioning service.

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

Provider/backend credentials for billing, carrier, DNS, and SBC management
should remain provider-side and must not be exposed to the PBX. Requirements for
any PBX-facing credentials are specific to a compatible provider integration.

## What Is Not Included

This repository contains the PBX-side module. It does not provide a billing
platform, carrier account, telephone-number inventory, DNS hosting, SBC
infrastructure, or a provisioning backend. Developers may implement compatible
services for those roles using their own systems; none of those particular
systems is required by the public module contract.

## Development and Testing

The repository includes PHP contract tests for the module scaffold, activation,
lifecycle/ownership and FreePBX compatibility. The activation tests use synthetic
fixtures and API doubles rather than live provider infrastructure. Run the
contracts with:

```sh
for test in tests/*_contract.php; do php "$test" || exit 1; done
```

Run PHP syntax checks on changed PHP files and `git diff --check` before
submitting changes. Do not put real activation tokens, public service
identifiers, telephone numbers, hostnames, addresses or provider credentials in
public fixtures.

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
