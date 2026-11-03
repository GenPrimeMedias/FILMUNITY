# FILMUNITY — Agent Instructions

## Mission and boundary

This repository contains FILMUNITY, an open-source multi-tenant association and union management platform. It is completely separate from any proprietary OTT platform. Do not inspect, modify, import, copy or reference external proprietary OTT code, databases, secrets, media or customer data.

## Architecture

- Laravel modular monolith.
- PostgreSQL.
- React + TypeScript + Inertia.js + Tailwind CSS.
- Centralised multi-tenant and self-hosted deployment modes.
- Apache License 2.0.

## Mandatory security rules

1. Never trust client-supplied tenant IDs.
2. Resolve tenant context on the server.
3. Enforce tenant scope in queries, policies, jobs, exports and file downloads.
4. Add cross-tenant negative tests for tenant-facing features.
5. Never expose private member, grievance or financial data.
6. Never commit secrets or real personal data.
7. Keep financial postings transactional and auditable.
8. Do not make election-integrity or legal-compliance claims without specialist review.

## Change process

- Inspect existing conventions before editing.
- Prefer minimal, focused changes.
- Add or update tests.
- Run formatter, static analysis, tests and frontend checks available in the repository.
- Update docs and ADRs for architectural changes.
- Never claim tests passed unless they were run and passed.
- Do not merge, deploy or release without explicit human authorisation.
