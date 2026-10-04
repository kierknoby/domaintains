# DOMAINTAINS 0.2.0

DOMAINTAINS is a FreePBX module for FreePBX 16 and 17.

## Activate

Request an activation key from your service provider. In FreePBX, open
**Connectivity > DOMAINTAINS**, enter the key, and select **Activate**. The CLI
also supports activation:

```sh
fwconsole domaintains activate
```

The command securely prompts for the key without displaying it. Do not place
the key in the command itself. Activation connects to the DOMAINTAINS
activation service. The module creates a local signing identity and does not
retain the activation key after successful activation.

After authorization, the module reconciles and verifies its local SIP trunk and
outbound route configuration. It requires PHP sodium and HTTPS support. Check
activation status with:

```sh
fwconsole domaintains status
fwconsole domaintains status --json
```

An activated installation cannot be linked to another service identity from
the module.

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