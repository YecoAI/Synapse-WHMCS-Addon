# Changelog

All notable changes to the Synapse WHMCS Addon are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.0] - 2026-08-23

### Added
- Production-ready WHMCS addon release for Synapse AI Autopilot
- Dual-LLM ticket automation with observe, copilot, and autopilot modes
- Secure webhook integration with HMAC signature validation and encrypted payloads
- In-dashboard updater, diagnostics, department mapping, and audit logging
- Multi-platform VM inventory support for Proxmox, VirtFusion, and Virtualizor

### Security
- Signed backend callbacks with replay protection
- Admin session and CSRF protection on addon AJAX endpoints
- Optional backend IP whitelist and least-privilege WHMCS API admin configuration

### Documentation
- Enterprise README and licensing terms for public distribution
- Full setup, configuration, and troubleshooting guide at [Synapse Docs](https://synapse.yecoai.com/docs/)
