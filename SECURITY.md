# Security Policy

## Supported versions

Security fixes are provided for the current release of Grey Rock Block Synchroniser for Wordfence and Cloudflare.

| Version | Supported |
|---|---|
| 1.2.x | Yes |
| 1.1.x and earlier | No |

Users should upgrade to the latest published release before reporting an issue that may already have been corrected.

## Reporting a vulnerability

Do not report suspected security vulnerabilities through a public GitHub issue, discussion, pull request, or other public channel.

Use GitHub Private Vulnerability Reporting for this repository:

1. Open the repository's **Security and quality** page.
2. Select **Advisories**.
3. Select **Report a vulnerability**.
4. Provide the technical details described below.

Include as much of the following information as possible:

- affected plugin version;
- affected WordPress version;
- PHP version;
- single-site or multisite configuration;
- Cloudflare operating mode:
  - Zone Access Rules; or
  - Account IP List;
- steps required to reproduce the issue;
- proof-of-concept code or request details;
- expected and observed behaviour;
- security impact;
- relevant logs with credentials, tokens, account IDs, zone IDs, IP addresses, and personal data redacted;
- suggested remediation, when available.

Do not include live Cloudflare API tokens, Global API Keys, WordPress authentication cookies, database credentials, SSH keys, or other secrets.

## Response process

Reports will be handled through coordinated vulnerability disclosure.

The maintainer will attempt to:

- acknowledge a complete report within five business days;
- assess reproducibility and security impact;
- communicate whether the report has been accepted, rejected, or requires more information;
- develop and test a correction privately when warranted;
- publish a fixed release before public technical disclosure;
- publish a GitHub Security Advisory when the issue materially affects users;
- request a CVE identifier when appropriate.

Response times may vary depending on severity, reproducibility, affected versions, and the complexity of the correction.

## Disclosure expectations

Reporters are asked to:

- avoid public disclosure until a correction is available or disclosure timing has been coordinated;
- avoid accessing, modifying, or deleting data that does not belong to them;
- avoid service disruption, denial-of-service testing, social engineering, and credential attacks;
- test only against systems and Cloudflare accounts they own or are explicitly authorised to assess;
- provide a reasonable opportunity to investigate and correct the issue.

## Security scope

Examples of security issues that are in scope include:

- unauthorised modification of Cloudflare rules or account-list entries;
- privilege or capability bypasses in WordPress administration;
- nonce or CSRF failures affecting privileged actions;
- exposure of Cloudflare API tokens or other stored credentials;
- unsafe multisite or network-administration behaviour;
- injection vulnerabilities;
- stored or reflected cross-site scripting;
- server-side request forgery;
- unsafe deserialisation;
- authentication or authorisation bypasses;
- unintended disclosure of security-sensitive configuration or logs.

General support requests, configuration questions, compatibility issues, and feature requests should use the normal GitHub issue process.

## Secret exposure

If a report contains an exposed credential, revoke or rotate that credential immediately. Do not wait for the vulnerability investigation to finish.

For Cloudflare credentials:

- revoke the affected API token;
- create a replacement restricted token;
- update the plugin configuration;
- review Cloudflare audit logs and recent list or rule changes.

For WordPress or server credentials:

- rotate the affected credential;
- invalidate active sessions where applicable;
- review relevant WordPress, web-server, SSH, and system logs.

## Credit

Security researchers who provide valid reports may be credited in the GitHub Security Advisory or release notes unless they request anonymity.
