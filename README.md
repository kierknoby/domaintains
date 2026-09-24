# DOMAINTAINS 0.1.0 for FreePBX 16 and 17

DOMAINTAINS (`domaintains`) is the PBX-side module for FreePBX/PBXact 16 and 17,
providing SIP trunking and numbering integration with DOMAINTAINS SBCs via
FreePBX UK's **my-connect** remote bridge.

DOMAINTAINS is the product/service/module installed on the PBX. **my-connect is
separate**: it is the remote bridge and orchestration layer used by FreePBX UK
to coordinate the PBX-side module with DOMAINTAINS infrastructure.

## Development status

Version 0.1.0 is an initial development scaffold. It intentionally implements
only module loading, a minimal GUI status page, and the `fwconsole domaintains
status` command. It does **not** yet create, modify or remove trunks, routes,
numbering or SBC configuration.

This development build is unsigned and should not be treated as a production
release.

## Compatibility

Use with FreePBX/PBXact 16 or 17.

## Current CLI

```sh
fwconsole domaintains
fwconsole domaintains status
fwconsole domaintains status --json
```

The default action is `status`.

Expected human-readable output begins with:

```text
DOMAINTAINS
===========
Version: 0.1.0
State: unprovisioned
Provisioned: no
Remote bridge: my-connect
FreePBX support: 16 and 17
```

The JSON form exists from the first scaffold so my-connect can later consume a
stable machine-readable status without scraping terminal output.

## Architecture

```text
WHMCS
  |
  | signed provisioning request
  v
my-connect
  |
  +--> DOMAINTAINS SBC infrastructure
  |
  +--> DOMAINTAINS module on the customer PBX
         |
         +--> FreePBX APIs locally
```

The intended separation of responsibility is:

- **WHMCS** knows the customer, service and commercial configuration.
- **my-connect** is the remote bridge and orchestrator.
- **DOMAINTAINS** owns and validates PBX-side configuration through local
  FreePBX APIs.
- **DOMAINTAINS SBCs** own SBC-side SIP and routing configuration.

The PBX module is intended to expose narrow, allowlisted operations rather than
arbitrary PHP, SQL or shell execution.

## Planned provisioning interface

The following commands are planned but are **not implemented in 0.1.0**:

```text
fwconsole domaintains provision
fwconsole domaintains verify
fwconsole domaintains remove
```

The provisioning path will be built from the already-proven FreePBX-native
trunk and outbound-route operations. Re-running the same desired configuration
must be safe and idempotent.

## Installing the development scaffold

Place the `domaintains` directory in `/var/www/html/admin/modules/`, then run:

```sh
cd /var/www/html/admin/modules/domaintains
fwconsole ma install domaintains
cd
fwconsole chown
fwconsole reload
```

The module appears under **Connectivity > DOMAINTAINS**.

Then verify the CLI:

```sh
fwconsole domaintains status
fwconsole domaintains status --json
```

## Repository

https://github.com/kierknoby/domaintains

## Licence

GPL-3.0-or-later. See `LICENSE`.

## AI-Assisted Contributions and Disclosure

This module may be developed with AI assistance for code generation, review,
testing and documentation. Generative AI assistance must be disclosed in every
commit containing AI-assisted changes:

```text
Assisted-by: AGENT_NAME:MODEL_VERSION
```

For example: `Assisted-by: GitHub-Copilot:gpt-5.6-sol`

The human contributor remains solely responsible for the contribution. AI tools
must not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne //
[FreePBX UK](https://github.com/freepbxUK)
