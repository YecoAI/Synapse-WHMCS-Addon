# Synapse AI Autopilot for WHMCS

Enterprise AI automation for hosting providers running WHMCS. Synapse ingests support tickets, applies policy-aware automation, and integrates with your infrastructure stack under operator control.

**Documentation:** [synapse.yecoai.com/docs](https://synapse.yecoai.com/docs/)

## Overview

Synapse AI Autopilot connects WHMCS to the Synapse Control Panel and delivers:

- AI-assisted ticket resolution with planner and verifier models
- Department-level automation modes: Observe, Copilot, and Autopilot
- Infrastructure-aware context for VPS and dedicated services
- Signed webhook communication and encrypted sensitive payloads
- Operator dashboards, diagnostics, and immutable activity logging

## Requirements

| Requirement | Version |
|---|---|
| WHMCS | 8.0 or newer |
| PHP | 8.0 or newer |
| Extensions | cURL, OpenSSL, JSON |
| License | Active Synapse Control Panel license |

## Quick Start

### 1. Install the addon

Extract the release package and copy the addon into WHMCS:

```bash
unzip synapse-whmcs-addon-0.9.0.zip
cp -r synapse/ /path/to/whmcs/modules/addons/
```

Ensure `modules/addons/synapse` is writable by PHP so in-dashboard updates can be applied.

### 2. Activate in WHMCS

1. Open **Setup → Addon Modules**
2. Activate **Synapse AI Autopilot**
3. Open **Configure** and enter your license key and backend URL

### 3. Configure departments

Open **Addons → Synapse AI Autopilot** and map support departments to the automation mode that matches your operational policy.

### 4. Verify connectivity

Use the built-in diagnostics panel to validate license status, backend reachability, database tables, and PHP extensions.

## Configuration

| Setting | Purpose |
|---|---|
| License Key | Authenticates your WHMCS installation with Synapse |
| Backend URL | Synapse API endpoint (default: `https://api-synapse.yecoai.com/api/v1`) |
| Confidence Threshold | Minimum AI confidence required for automated actions |
| Callback HMAC Secret | Populated automatically for signed backend callbacks |
| WHMCS API Admin | Dedicated least-privilege admin used for local API operations |

Detailed configuration, security guidance, and integration steps are published in the [Synapse documentation](https://synapse.yecoai.com/docs/).

## Updating

After installation, open **Addons → Synapse AI Autopilot**. The dashboard checks for newer packages and supports one-click updates when the addon directory is writable.

## Security

- HMAC-SHA256 signatures on API traffic and backend callbacks
- AES-256-GCM encryption for sensitive ticket payloads
- Optional backend IP allowlisting
- Admin authentication and CSRF protection on addon endpoints

## Support

| Channel | Link |
|---|---|
| Documentation | [synapse.yecoai.com/docs](https://synapse.yecoai.com/docs/) |
| Support portal | [support.synapse.yecoai.com](https://support.synapse.yecoai.com) |
| Email | support@synapse.yecoai.com |

Enterprise support is available for Business and Enterprise plans.

## License

Proprietary software. See [LICENSE](LICENSE) for terms of use.

Copyright © 2026 YecoAI. All rights reserved.
