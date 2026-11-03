# FILMUNITY — Master Project Blueprint

**Document status:** Baseline specification  
**Version:** 0.1.0  
**Licence:** Apache License 2.0  
**Project owner:** Founder / Project Lead  
**Initial geography:** Tamil Nadu, Kerala, Karnataka, Andhra Pradesh and Telangana

## 1. Executive summary

FILMUNITY is an open-source, multi-tenant platform for the operational administration of film-industry associations, federations, councils and workers' unions. It provides reusable software for organisation administration, membership, digital identity, office-bearers, welfare, grievances, finance, professional directories, meetings, documents, communications, compliance records and reporting.

The platform is not an industry regulator or governing authority. Each participating organisation retains its own constitution, leadership, membership rules, decisions, financial accountability and data governance.

## 2. Product goals

1. Replace fragmented paper/spreadsheet workflows with auditable digital processes.
2. Allow multiple independent organisations to use one centrally hosted deployment.
3. Allow an organisation or service provider to deploy an independent self-hosted installation.
4. Protect personal, financial, grievance and organisational data.
5. Support Tamil, Telugu, Kannada, Malayalam and English.
6. Build an accessible, documented and contributor-friendly open-source codebase.
7. Keep the commercial OTT platform completely separate.

## 3. Non-goals

- FILMUNITY will not govern or represent participating associations.
- FILMUNITY will not make membership eligibility decisions automatically.
- FILMUNITY will not provide legal, tax or accounting advice.
- FILMUNITY will not initially provide legally binding electronic voting. Election workflows are administrative until legal and security review establishes otherwise.
- FILMUNITY will not contain OTT streaming, content delivery, subscription, advertising or media-encoding functionality.
- FILMUNITY will not publish member data merely because the software is open source.

## 4. Deployment models

### 4.1 Centralised multi-tenant service

A single application deployment serves multiple organisations. Each request is bound to an authenticated organisation context. Tenant-owned records are scoped and authorised by the server. Organisations receive independent administration, settings, roles, data exports and retention controls.

### 4.2 Self-hosted installation

An organisation or approved operator deploys a separate instance, database, storage, secrets and backups. The same tagged application release and documented upgrade process are used. Self-hosted mode must not depend on a central FILMUNITY service for core membership workflows.

### 4.3 Deployment-neutral design

Avoid hard-coded domain names, provider-specific payment logic and central-only assumptions. Use environment configuration, adapters/interfaces for external services, documented backup/restore and repeatable migrations.

## 5. Architecture

Use a modular monolith with explicit domain boundaries. Laravel is the application framework; modules are logical boundaries within one deployable application. Do not introduce microservices until measured scale, team ownership or isolation requirements justify them.

### Logical layers

1. **Presentation:** React, TypeScript, Inertia, Tailwind.
2. **HTTP/application:** routes, request validation, controllers, application services/actions.
3. **Domain:** domain rules, policies, value objects and module contracts.
4. **Persistence:** Eloquent models, repositories only where they add value, migrations and database constraints.
5. **Infrastructure:** queues, mail, SMS/WhatsApp adapters, object storage, payment adapters, logging and monitoring.

### Proposed module boundaries

- Platform: tenant registry, platform configuration, support operations.
- Organisations: profiles, branches, settings and organisation lifecycle.
- Identity & Access: users, people, tenant memberships, roles and permissions.
- Membership: applications, categories, status, renewals and history.
- Digital ID: card issuance, QR verification and revocation.
- Governance: committees, office-bearers, terms and resolutions.
- Welfare: schemes, applications, decisions and disbursements.
- Grievances: confidential cases, assignments, actions and appeals.
- Finance: chart of accounts, journals, receipts, payments and reconciliation.
- Professional Directory: skills, categories, portfolios and visibility preferences.
- Meetings & Events: agendas, attendance, minutes and action items.
- Elections: nominations, voter eligibility, ballot workflows and audit evidence (later phase).
- Communications: notices, templates, delivery logs and preferences.
- Documents: metadata, versions, access rules and retention.
- Compliance: filing calendars, policies and evidence records.
- Reporting: permission-aware operational reports and exports.

## 6. Tenant and identity model

A `person` is a human identity record. A `user` is an authentication principal. A `tenant_membership` links a person to an organisation and stores that organisation-specific membership relationship. One person may have multiple memberships in different organisations. Do not assume that membership in one organisation grants visibility into another.

Every tenant-owned table must have a non-null `tenant_id` unless the data is explicitly global reference data. Tenant context must be resolved server-side from an authenticated, authorised organisation selection or tenant-specific host mapping. Never trust a raw client-supplied tenant ID.

Apply defence in depth:

- central tenant context middleware/service;
- tenant-aware policies and query scopes;
- database foreign keys and composite unique constraints;
- PostgreSQL row-level security where correctly configured and tested;
- tenant-scoped storage paths and signed download authorisation;
- tenant context propagation into jobs, exports, notifications and scheduled tasks;
- automated cross-tenant negative tests.

A global platform operator must not receive automatic unrestricted access to member, grievance or financial data. Support access should be time-bound, reason-coded, audited and least-privilege.

## 7. Security and privacy requirements

- MFA for privileged roles.
- Secure password hashing and session management.
- CSRF protection and secure cookies.
- Rate limits for login, OTP and public verification endpoints.
- Encrypt transport using TLS; encrypt backups and sensitive object storage.
- Store secrets outside source control.
- Use least-privilege roles and explicit policies.
- Keep immutable or append-only audit evidence for sensitive operations.
- Do not log passwords, tokens, full identity documents, payment secrets or grievance narratives.
- Use private object storage; authorise each download.
- Establish retention, correction, export and deletion workflows.
- Define incident response, backup restoration and disaster recovery procedures.
- Complete a privacy and legal review for applicable Indian data-protection and sectoral requirements before processing live member data.

## 8. Functional scope and delivery priority

### Release 0 — Engineering foundation

- Repository, licence, contribution governance.
- Laravel bootstrap and environment configuration.
- CI, lint/static analysis and test harness.
- PostgreSQL connection and migration baseline.
- Health endpoint and deployment documentation.

### Release 0.1 — Tenant foundation

- Organisations and organisation status.
- Tenant context resolution.
- Users, people and tenant memberships.
- Role/permission model.
- Cross-tenant security tests.
- Tenant-aware audit events.

### Release 0.2 — Membership MVP

- Membership categories and configurable requirements.
- Application, review and approval workflow.
- Member profile and status history.
- Renewal and membership number allocation.
- CSV import/export with permission checks.
- Digital membership card and privacy-preserving QR verification.

### Release 0.3 — Administration

- Office-bearers and committee terms.
- Notices, meetings, documents.
- Basic welfare application workflow.
- Basic grievance workflow with restricted access.

### Release 0.4 — Financial and directory modules

- Double-entry ledger, receipts and reconciliation.
- Payment gateway adapter (provider selected separately).
- Professional directory and visibility controls.
- Reports and controlled exports.

### Release 0.5+ — Advanced governance

- Elections after independent legal/security design.
- Compliance calendars.
- Advanced analytics.
- Additional integrations and regional workflows.

## 9. Acceptance criteria for first milestone

The first milestone is complete only when:

1. A public GitHub repository exists with Apache-2.0 licence and governance files.
2. Laravel application boots from documented clean-install instructions.
3. `.env.example` contains placeholders only.
4. PostgreSQL migrations run on a clean database.
5. CI runs on pull requests and the default branch.
6. Automated tests run successfully in CI.
7. Code formatting/static analysis checks are configured.
8. No secrets, production data or OTT assets are present.
9. README explains local setup, tests and project boundaries.
10. A tagged initial development release can be reproduced from a clean checkout.

## 10. Operational principles

- Use UTC timestamps in storage; display in the organisation's configured timezone.
- Store monetary values as integer minor units with explicit currency, or exact decimal types; never floating point.
- Use immutable journal entries with reversals rather than destructive edits.
- Make important workflows transactional and idempotent.
- Use asynchronous jobs for bulk imports, exports, notifications and document generation.
- Make external integrations replaceable through interfaces/adapters.
- Provide accessible interfaces and keyboard navigation.
- Keep audit logs separate from ordinary application logs.
- Maintain a public changelog and release notes.

## 11. Project success measures

Track actual values, not vanity metrics:

- number of active pilot organisations;
- active members managed (aggregated, privacy-safe);
- issue response and resolution times;
- pull requests reviewed and merged;
- independent contributors;
- release cadence;
- automated test results and coverage trends;
- tenant isolation test results;
- security findings and remediation times;
- successful backup restoration drills.

## 12. Open decisions

Before production:

- legal review of licence, privacy notices and organisation data-processing roles;
- final identity and account recovery model;
- tenant host/domain mapping strategy;
- whether any large tenant requires a dedicated database;
- payment provider selection and reconciliation requirements;
- SMS/WhatsApp providers and consent model;
- records retention schedules;
- election legality and independent audit requirements;
- production hosting and support responsibility model.
