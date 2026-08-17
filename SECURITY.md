# Security Policy

## Supported version

Security fixes are applied to the latest version on the default branch.

## Reporting a vulnerability

Use GitHub's **Security → Report a vulnerability** flow to send a private
report to the maintainers. Include the affected endpoint or component,
reproduction steps, impact, and any suggested mitigation.

Please do not disclose a suspected vulnerability in a public issue before the
maintainers have had a reasonable opportunity to investigate and publish a
fix.

## Deployment scope

ClipLocal is designed for a trusted local machine. It binds to `127.0.0.1` and
rejects non-local request hosts and origins. Do not expose it directly to the
public internet without adding authentication, HTTPS, request limits, and an
explicit trusted-host configuration.
