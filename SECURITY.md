# Security Policy

## Supported Versions

The following versions of AirX currently receive security updates:

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

---

## 🔒 Reporting a Vulnerability

The AirX engineering team at LifeBank takes security seriously. If you discover a security vulnerability in AirX, please report it responsibly.

### How to Report

* **Email**: Please email details of the vulnerability directly to **[developer@lifebank.ng](mailto:developer@lifebank.ng)**.
* **Do NOT** file a public GitHub issue for security vulnerabilities.
* Please include:
  * Description of the vulnerability and its potential impact.
  * Steps to reproduce the issue or proof-of-concept (POC) payload.
  * Affected endpoint(s), file(s), or component(s).
  * Any proposed mitigation or fix (if available).

### Response SLA & Timeline

1. **Acknowledgment**: Within **48 hours** of receiving your report.
2. **Triage & Assessment**: Within **5 business days** confirming the validity and severity.
3. **Remediation**: We will work to release a patch and advise affected installations before public disclosure.
4. **Credit**: We will gladly credit you in release notes (unless you prefer to remain anonymous).

---

## 🏥 Clinical Data Privacy & Protection

AirX is architected under strict medical data protection principles:
* **No Individual Patient Telemetry**: The platform strictly rejects patient-level clinical records (`gender`, `age`, `conditions`, `treatment`, `flow_rate`) with HTTP `422 Unprocessable Entity`.
* **Aggregated Consumption Only**: Only facility-level monthly usage totals (`hospital_id`, `period`, `oxygen_used_m3`) are persisted for machine learning demand forecasting.
* **Authentication Hardening**: JWT tokens are signed using SHA-256 with timing-safe comparison, rate-limited login endpoints, and sanitized server-side error logging.
