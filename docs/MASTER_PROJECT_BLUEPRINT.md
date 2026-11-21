# FILMUNITY — Master Project Blueprint

**Document status:** Baseline specification  
**Version:** 0.1.0  
**Licence:** Apache License 2.0  
**Project owner:** Founder / Project Lead  
**Initial geography:** Tamil Nadu, Kerala, Karnataka, Andhra Pradesh and Telangana

## 1. Executive summary

FILMUNITY is an Apache-2.0 open-source platform for the film-industry professional community and the independent administration of film-industry associations, federations, councils and workers' unions.

The platform serves two complementary product domains:

- **Global professional community:** Individuals can create free professional identities, maintain film-industry profiles, showcase credits and portfolios, discover other professionals and build professional connections independently of association membership.
- **Association management:** Participating organisations can manage membership, digital identity, governance, welfare, grievances, finance, documents, communications and reporting through strictly isolated association-owned workflows.

Initial regional focus includes the Tamil, Malayalam, Telugu and Kannada film industries, with multilingual support and scope for future expansion.

Public professional profiles require approved completion, consent and moderation conditions. The planned public profile route is `/community/@username`; a profile must reach 100% of the defined completion requirements before publication eligibility.

FILMUNITY is not an industry regulator or governing authority. Each participating organisation retains its own constitution, leadership, membership rules, decisions, financial accountability and data governance.

FILMUNITY remains entirely separate from any proprietary OTT streaming platform.

## 2. Product goals

1. Provide free professional profiles and networking for film-industry individuals.
2. Enable privacy-aware discovery of professional skills, credits and portfolios.
3. Replace fragmented association paper and spreadsheet workflows with auditable digital processes.
4. Offer free centrally hosted, multi-tenant association management with strict tenant isolation.
5. Support independent self-hosted Pro deployments with optional proprietary licensed services or modules, without restricting Apache-2.0 core rights.
6. Preserve organisation autonomy over membership eligibility, governance and financial decisions.
7. Protect personal, financial, grievance and organisational data.
8. Support Tamil, Telugu, Kannada, Malayalam and English.
9. Build an accessible, documented and contributor-friendly open-source codebase.
10. Keep the commercial OTT platform completely separate.

## 3. Non-goals

- FILMUNITY will not govern or represent participating associations.
- FILMUNITY will not make membership eligibility or movie-title registration approval decisions automatically.
- FILMUNITY will not provide legal, tax or accounting advice.
- FILMUNITY will not initially provide legally binding electronic voting. Election workflows are administrative until legal and security review establishes otherwise.
- FILMUNITY will not contain OTT streaming, content delivery, subscription, advertising or media-encoding functionality.
- FILMUNITY will not publish professional or member data without the required consent, publication eligibility and access controls.
- FILMUNITY will not automatically federate identities or databases between central and self-hosted installations.

## 4. Deployment models

### 4.1 Centralised multi-tenant service

FILMUNITY centrally hosts the global professional community and offers free association management through a shared multi-tenant deployment.

Global professional identities and public community records are distinct from association-owned private data. Each association receives its own authorised tenant context, administration, settings, roles, data exports and retention controls.

Tenant-owned records must be isolated through application authorisation, database constraints and appropriately tested PostgreSQL Row-Level Security where enabled. Cross-association workflows require explicitly authorised, minimal data sharing.

### 4.2 Self-hosted Pro installation

An association or approved operator may deploy an independent branded self-hosted instance with its own database, authentication, storage, secrets, backups and operational responsibility.

Optional proprietary Pro services or modules may require separate commercial licensing. The Apache-2.0 core remains available under its existing licence, and Pro licensing must not revoke or restrict open-source rights.

Core association workflows must not require continuous connectivity to a central FILMUNITY service. Central identity linking and federation are not assumed and require separate architectural approval.

### 4.3 Deployment-neutral design

Avoid hard-coded domain names, provider-specific payment logic and central-only assumptions. Use environment configuration, adapters/interfaces for external services, documented backup/restore and repeatable migrations.

Deployment-specific branding, configuration and optional modules must not compromise tenant isolation, authentication boundaries or the rights granted by the Apache-2.0 core licence.


## 5. Architecture

FILMUNITY uses a modular monolith with explicit domain boundaries. Laravel is the application framework; modules are logical boundaries within one deployable application. Microservices are not introduced unless measured scale, team ownership or isolation requirements justify them.

The existing application foundation uses Laravel 13, PHP 8.3, PostgreSQL 18, React 19, TypeScript, Inertia 3 and Tailwind CSS 4.

### Logical layers

1. **Presentation:** React, TypeScript, Inertia and Tailwind.
2. **HTTP/application:** routes, request validation, controllers, application services and actions.
3. **Domain:** business rules, policies, value objects and module contracts.
4. **Persistence:** Eloquent models, repositories where useful, migrations and database constraints.
5. **Infrastructure:** queues, mail, SMS/WhatsApp adapters, object storage, payment adapters, logging and monitoring.

### Proposed module boundaries

- Platform: organisation registry, platform configuration, approvals, support operations and audit.
- Global Identity: existing Laravel users, people, account linking and identity-claim workflows.
- Professional Community: global professional profiles, categories, skills, credits, portfolios, discovery, connections, reports, completion, consent and moderation.
- Organisations: applications, verification, profiles, branches, settings, domains and lifecycle.
- Tenant Identity & Access: tenant affiliations, association roles, permissions and assignments.
- Membership: association-owned versioned classification catalogs, immutable application submissions, review and approval, one authoritative permanent membership per canonical person per association, created only at successful first activation, independent standing and validity, renewal-period history and auditable classification changes.
- Digital ID: association card issuance, privacy-preserving QR verification and revocation.
- Movie-Title Registration: optional producer-association applications, registrations, cross-association checks, recipient responses and audited decisions.
- Governance: committees, office-bearers, terms and resolutions.
- Welfare: schemes, applications, decisions and disbursements.
- Grievances: confidential cases, assignments, actions and appeals.
- Finance: chart of accounts, journals, receipts, payments and reconciliation.
- Meetings & Events: agendas, attendance, minutes and action items.
- Elections: nominations, voter eligibility, ballot workflows and audit evidence (later phase).
- Communications: notices, templates, delivery logs and preferences.
- Documents: metadata, versions, access rules and retention.
- Compliance: filing calendars, policies and evidence records.
- Reporting: permission-aware operational reports and exports.

The authoritative entity definitions, tenant constraints, financial invariants, indexing and migration sequence are documented in `docs/database/DATABASE_ARCHITECTURE.md`.

## 6. Tenant and identity model

### 6.1 Existing authentication foundation

FILMUNITY extends the existing Laravel authentication system rather than replacing it.

- `users.id` remains the existing BIGINT primary key.
- Preserve Laravel Fortify, passkeys, multi-factor authentication, sessions and existing authentication behaviour.
- `people.id` is a UUID global person identifier.
- `people.user_id` is an optional unique BIGINT reference to `users.id`.
- `professional_profiles.id` is UUID and references a global person independently of association membership.

### 6.2 Global professional identity

A person may maintain a global professional identity and community profile without joining an association.

Public profile publication requires 100% completion of defined mandatory fields, consent and applicable moderation approval. Publication eligibility must not bypass privacy controls.

Global professional profiles and community relationships are distinct from association-owned private records.

### 6.3 Tenant affiliation and approved membership

An organisation is a tenant with UUID `organisations.id`.

`tenant_memberships` represents an association-specific affiliation or access relationship. It must not be treated as automatic proof of approved association membership.

Formal membership applications use association-specific categories and versioned forms. Submitted applications retain the exact form version and answers used.

Approved applications and permanent formal membership are separate. Application approval initiates a pending activation case; the permanent `memberships` record and membership number are created only upon successful first activation after all required gates. Membership numbers, standing, renewals, documents and digital cards remain association-owned.

An association-specific affiliation is created or reused when a person submits their first valid membership application to that association. Application submission does not confer approved membership or association-member permissions. Rejected applicants may reapply subject to association-controlled waiting periods, documented restrictions and auditable decisions. The same affiliation is reused for subsequent applications and renewals.

One person may be associated with multiple organisations, but access in one association does not grant visibility into another association.

Only the first valid submitted membership application creates or reuses a persistent association affiliation. Drafts and staff invitations do not create affiliations. A person retains a persistent original-identity affiliation; after successful first activation, the current authoritative membership is uniquely resolved by tenant and canonical person, with superseded historical memberships preserved. Reapplications, renewals and authorised rejoining reuse these identities.

Affiliation relationship statuses are `active`, `restricted` and `closed`; restricted or closed affiliations deny normal tenant operations. Limited appeal, recovery and compliance exceptions require explicit authorisation and audit.

Membership standing and validity are independent. Time-limited memberships expire after the final valid association-local calendar date, without reliance on expiry-worker timing. Approved renewals preserve membership identity and number, with immutable validity-period history; renewal during suspension does not restore privileges.

Operational staff access requires an active affiliation, approved current unsuspended formal membership, active staff appointment and tenant-scoped RBAC permission. Staff invitations alone grant no operational access.

Each association owns its classification catalog and eligibility rules. One formal membership may hold multiple approved classifications. Material eligibility-rule changes create immutable versions; historical approvals preserve the exact version used. New rules apply prospectively unless an authorised audited reassessment is performed.

Correction requests and resubmissions preserve the same application identity with immutable historical submission snapshots and review events.

### 6.4 Tenant isolation

Every tenant-owned table must have a non-null UUID `tenant_id` unless an alternative ownership model is explicitly documented and approved.

Tenant context must be resolved server-side through authenticated, authorised association selection or host mapping. Never trust a raw client-supplied tenant identifier.

Apply defence in depth:

- central tenant context middleware and services;
- tenant-aware policies and query scopes;
- tenant-scoped foreign keys and composite uniqueness constraints;
- PostgreSQL Row-Level Security when correctly configured and tested;
- tenant-scoped storage and authorised downloads;
- validated tenant context in jobs, exports, notifications and scheduled tasks;
- automated cross-tenant denial tests.

### 6.5 Cross-association workflows

Optional movie-title registration is restricted to authorised producer associations. The underlying registration records are represented by `movie_title_registrations` in `docs/database/DATABASE_ARCHITECTURE.md`.

Cross-association title verification shares only the minimum information required for authorised recipients to respond.

Each recipient check retains its own authoritative response deadline, with a default configurable period of 30 calendar days. Expiry never automatically approves a registration.

Responses and final registration decisions require verified authority and audit records.

Cross-association workflows must not disable tenant isolation or provide unrestricted access to another association.

### 6.6 Platform administration and self-hosting

Global platform operators must not receive automatic unrestricted access to association member, grievance or financial records.

Support access must be authorised, time-bound, reason-coded, audited and least-privilege.

Self-hosted Pro instances use independent databases and authentication by default. Federation and central identity linking require separate architectural approval.


## 7. Security and privacy requirements

- Preserve Laravel Fortify, existing authentication guards, passkeys, multi-factor authentication and session security.
- Require MFA for privileged roles.
- Use secure password hashing, CSRF protection, secure cookies and appropriate session management.
- Rate-limit login, OTP, public profiles, reports and public verification endpoints.
- Encrypt transport using TLS; encrypt backups and sensitive object storage.
- Store secrets outside source control.
- Apply least-privilege roles, explicit policies and server-authorised tenant context.
- Enforce tenant ownership through application policies and database constraints.
- Introduce PostgreSQL Row-Level Security only after database roles, transactions, connection pooling and fail-closed policies are tested.
- Validate tenant context independently in HTTP requests, queue jobs, retries, imports, exports and scheduled tasks.
- Maintain immutable or append-only audit evidence for sensitive operations.
- Do not log passwords, tokens, full identity documents, payment secrets or grievance narratives.
- Use private object storage and authorise every protected download.
- Require defined profile completion, consent, visibility controls and applicable moderation approval before public professional profile publication.
- Prevent public community features from exposing private association membership, grievance, welfare or financial records.
- Provide reporting, moderation and abuse-prevention workflows for community content.
- Require explicitly authorised, minimal information sharing for cross-association movie-title verification.
- Authenticate or verify recipient responses and retain attributable audit records.
- Establish retention, correction, export and deletion workflows.
- Define incident response, backup restoration and disaster recovery procedures.
- Complete privacy and legal review for applicable Indian data-protection and sectoral requirements before processing live personal or association data.

## 8. Functional scope and delivery priority

The following releases describe user-facing delivery priorities. They do not replace the database dependency order defined in `docs/database/DATABASE_ARCHITECTURE.md`.

### Release 0 — Existing foundation and engineering readiness

- Preserve the existing Laravel 13 application, BIGINT users, Fortify, passkeys, MFA and working migrations.
- Confirm repository licensing, Apache-2.0 notices and contribution governance.
- Verify local environment configuration, PostgreSQL connectivity and reproducible setup.
- Establish CI, formatting, static analysis and automated testing.
- Document deployment, backup, restore and operational health checks.
- Avoid recreating existing authentication or database foundations.

### Release 0.1 — Global professional identity and community foundation

- Introduce global people records linked optionally to existing users.
- Introduce professional profiles, categories, skills, credits and portfolios.
- Define username normalisation and unique public identity rules.
- Implement profile completion, consent, moderation and publication eligibility.
- Support public `/community/@username` profiles only after 100% required completion and applicable approvals.
- Establish privacy-aware discovery, reporting and initial professional connections.
- Keep global community identity independent of association membership.

### Release 0.2 — Organisation onboarding and tenant foundation

- Introduce organisation applications, verification, approval and provisioning.
- Establish organisations, settings and authorised tenant context.
- Implement tenant affiliations, roles, permissions and tenant-aware audit events.
- Enforce tenant-scoped database relationships and cross-tenant denial tests.
- Keep centrally hosted association management free.

### Release 0.3 — Association membership MVP

- Introduce membership categories and versioned application forms.
- Implement membership application submission, review and human approval.
- Keep applications distinct from approved association memberships.
- Implement one authoritative permanent membership per canonical person per association, created only at successful first activation, independent standing and validity, association-scoped membership numbers, controlled renewals and immutable renewal-period history.
- Provide permission-controlled import and export.
- Introduce digital membership cards and privacy-preserving QR verification.
- Implement the approved first-valid-submission affiliation lifecycle, immutable correction submissions, staff-membership prerequisites and association-owned versioned classification governance.

### Release 0.4 — Association administration and optional title registration

- Implement office-bearers, committee terms, notices, meetings and documents.
- Introduce basic welfare and restricted grievance workflows.
- Offer movie-title registration only to authorised producer associations.
- Support association-scoped title uniqueness and authorised cross-association checks.
- Apply recipient-specific response deadlines, defaulting to configurable 30 calendar days.
- Require human registration decisions; deadline expiry must never trigger automatic approval.

### Release 0.5 — Optional finance and operational modules

- Introduce double-entry accounting, immutable postings, reversals and reconciliation.
- Add payment adapters after provider selection and security review.
- Expand communications, reporting, controlled exports and compliance workflows.
- Maintain tenant isolation, accounting integrity and auditability.

### Release 0.6+ — Advanced governance and self-hosted Pro

- Introduce election features only after independent legal and security design.
- Expand analytics, integrations and regional workflows.
- Support independently deployed and branded self-hosted Pro installations.
- Keep Apache-2.0 core rights intact; separately license only appropriate proprietary Pro services or modules.
- Treat federation and central identity linking as separately approved future capabilities.

Features may be delivered incrementally within each release. Optional modules are not prerequisites for the global community or association membership MVP.

## 9. Acceptance criteria for first milestone

The first milestone establishes a verified engineering baseline for the existing FILMUNITY application. Existing functionality must be inspected and tested rather than assumed complete or recreated.

Completion requires:

1. Repository licensing and governance files are verified, including Apache-2.0 core licensing.
2. The existing Laravel 13 application boots from documented local setup instructions.
3. Existing BIGINT `users.id`, Fortify, passkeys, MFA and authentication behaviour remain intact.
4. Existing migrations are preserved and tested against an isolated development or test database.
5. `.env.example` contains placeholders only, with no committed credentials.
6. PostgreSQL configuration and connection requirements are documented.
7. CI executes automated tests and configured formatting or static-analysis checks.
8. No production secrets, personal data or proprietary OTT assets are included.
9. README documents setup, tests, licensing, deployment boundaries and the separate OTT project.
10. A reproducible development build or tagged release is documented.
11. Initial global identity and tenant architecture contracts are reviewed before new migrations.
12. Database changes are not applied to production or unrelated databases without an approved migration and backup plan.

These criteria are acceptance requirements, not claims that all listed capabilities are already implemented.


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

Track actual values, not vanity metrics. Report adoption, workflow quality, security and operational reliability without exposing private individual or association information.

### Professional community

- Number of registered people and active professional profiles.
- Number and percentage of profiles meeting 100% required completion.
- Number of profiles eligible for public publication after consent and applicable moderation.
- Number of published `/community/@username` profiles.
- Community reports, moderation response times and resolution outcomes.
- Privacy-safe professional discovery and engagement measures.

### Association management

- Number of verified, active pilot organisations.
- Organisation onboarding completion and provisioning failure rates.
- Active members managed, aggregated in a privacy-safe manner.
- Membership application submission, review and decision times.
- Membership renewals and digital ID verification reliability.
- Tenant isolation test results and cross-tenant access denial coverage.
- Association support issue response and resolution times.

### Optional advanced workflows

- Movie-title registration volume, human decision times and outstanding checks.
- Recipient-specific title-check response times and deadline compliance.
- Financial reconciliation exceptions and accounting integrity checks.
- Adoption and reliability of optional governance and operational modules.

### Engineering and operations

- Pull requests reviewed and merged.
- Independent contributors and release cadence.
- Automated test results, coverage trends and build reliability.
- Security findings, severity and remediation times.
- Successful backup restoration drills and recovery-time results.
- Production incidents, service availability and recovery performance.
- Self-hosted Pro deployment and upgrade reliability when that offering is introduced.

Use documented metric definitions, appropriate aggregation and access controls. Establish baselines before setting numerical targets.

## 12. Open decisions

The following decisions must be resolved and documented before implementing their dependent features or releasing them to production.

### Identity, community and privacy

- Final identity, account recovery and optional person-to-user linking rules.
- Username reservation, normalisation, renaming and public profile URL permanence.
- Required professional profile fields and the exact 100% completion calculation.
- Consent, moderation approval, visibility, reporting and publication withdrawal rules.
- Legal review of Apache-2.0 licensing, privacy notices, applicable Indian data-protection requirements and organisation data-processing roles.
- Personal information retention, correction, export and deletion schedules.

### Tenant and membership lifecycle

- Final concurrent-application handling, correction-submission storage and detailed transition authorisation.
- Organisation verification, approval and suspension authority.
- Tenant host/domain mapping and tenant resolution strategy.
- Whether large tenants require dedicated databases.
- Detailed membership numbering allocation, appeals, staff appointment lifecycle, classification constraints and migration-safe enforcement of approved renewal and suspension policies.
- Database Row-Level Security rollout, connection-pooling compatibility and tenant-role separation.

### Movie-title registration

- Eligibility and authority of producer associations to issue registrations.
- Title normalisation, language rules, conflict handling and association-local uniqueness.
- Cross-association verification consent, recipient selection and minimal shared information.
- Recipient-specific response deadline configuration, reminders and escalation.
- Human decision authority and handling of unanswered or expired checks; no automatic approval.
- Legal scope and limitations of title registration or verification claims.

### Finance, governance and integrations

- Payment provider selection, fees, settlement and reconciliation requirements.
- SMS/WhatsApp providers, consent requirements and communication preferences.
- Election legality, eligibility rules, independent audits and dispute handling.
- Production hosting, backups, incident response and support responsibility model.

### Licensing and deployment

- Final boundary between Apache-2.0 core components and separately licensed Pro services or modules.
- Self-hosted Pro licensing, updates, support commitments and deployment ownership.
- Whether future federation or cross-instance identity linking is required.
- Branding, domain ownership, data portability and termination obligations for Pro installations.

Unresolved items are not implementation approvals. Record each decision with an owner, rationale, security and privacy implications, and the affected release before implementation.

## 13. Approved membership and database architecture decisions — Steps 32–33

**Decision register:** Step 32.1–32.28 (28 approved); Step 33.A1–A6 (6), B1–B8 (8), C1–C24 (24), D1–D22 (22): **88 decisions total**. This section summarises approved product and operational requirements; the detailed logical schema, keys, constraints and transaction invariants are in `docs/database/DATABASE_ARCHITECTURE.md`, Section 10. These rules supersede any earlier contradictory shorthand suggesting that formal membership is created at application approval. Step 34 decisions are intentionally excluded.

### 13.1 Identity, affiliation and permanent membership

- Preserve existing Laravel BIGINT `users.id` and Fortify/passkeys/MFA. Global UUID `people` identities and professional profiles are independent of association records; imported provisional people may have no account.
- Create or reuse an association's persistent `tenant_memberships` affiliation only on first valid **submitted** application, not draft or staff invitation. Affiliation `active`, `restricted` and `closed` is distinct from application decision, membership standing, entitlement and staff role; affiliation alone grants no member access.
- Application `changes_requested` permits correction and resubmission of the same application with immutable submitted revisions. Approval, rejection and withdrawal retain audit and document evidence; approved application is **not** activated membership.
- Create permanent `memberships` UUID and permanent membership number **only on successful first activation**, once verified payment/approved concession, verification, intended date, affiliation, standing, identity and security requirements pass. Track pending activation separately; no retroactive privileges, duplicate activation or number reuse.
- Renewals, reinstatement, expiry restoration, classification changes and rejoining preserve permanent membership ID and number. Versioned tenant numbering format and sequence, controlled legacy import, conflict quarantine and audited correction protect historical numbering.
- Imported membership claims require verified ownership and independent dispute review, not first-claim priority or contact/name matching. Revoke wrongful account links and derived access immediately; retain membership history and allow independently verified rightful recovery.
- Non-destructive cross-association canonical identity reconciliation preserves original person UUIDs and tenant membership records, numbers, standing, staff and financial history. Same-tenant duplicate authoritative membership conflicts block the entire global merge until tenant-authorised resolution. Merges/reversals use direct canonical mappings, optimistic versioned plans, deterministic locks, immutable history and security epochs.
- One current authoritative membership per `(tenant_id, canonical_person_id)` may grant derived membership privileges; superseded historical memberships do not. The exact physical authority-resolution table is a later Step 34 design decision.

### 13.2 Access, renewals, classifications and lifetime rights

- Membership access requires current authoritative ownership, active affiliation, permitted standing, effective entitlement, security checks and relevant RBAC. Staff additionally requires a current active appointment and valid unsuspended membership; renewals never bypass suspension.
- Versioned tenant renewal policy chooses anniversary-based or activation-based periods. Policy changes are prospective, with separately authorised exceptional adjustments; early renewal does not overlap existing validity.
- Time-limited validity ends at the next association-local midnight after `valid_until`, converted to UTC; enforcement is immediate regardless of worker timing. Lifetime validity has no temporal upper bound and never bypasses standing or security restrictions.
- Preserve immutable entitlement grants and compensating events, with a separate non-overlapping effective-interval projection. Authorised fixed-term→lifetime conversion ends the current fixed term, starts lifetime at the same instant and supersedes overlapping future scheduled renewals, without erasing paid history.
- Erroneous cancellation and restoration require separate authorised events and fresh checks. Cancelling lifetime conversion never automatically revives superseded fixed-term rights. Paid superseded renewals require separate policy-controlled financial resolution (refund, credit, adjustment or no relief).
- Multiple classifications may be approved per membership. Preserve versioned tenant eligibility rules, exact approval versions and historical decisions; reassessment is explicit and audited.

### 13.3 Fees, verified payments and concessions

- Issue immutable line-level fee assessments bound to published policy version and validity window. Partial/multiple verified payments allocate to individual obligations with locked ceilings, tenant/currency checks and immutable reversal/reallocation for corrections.
- Ordinary activation requires full verified financial fulfilment after approved concessions; instalment exceptions require separately authorised versioned policy. Waivers, discounts, zero-fee, honorary and hardship approvals are not fictional cash receipts.
- Expired assessments cannot activate membership merely because a partial payment exists. Preserve receipts and use authorised replacement assessment/reallocation. Overpayments remain unapplied funds until authorised refund, credit or other disposition.
- Chargebacks, verified refunds, fee adjustments and dispute recovery change the financial position immediately and immutably. Their access consequences follow versioned tenant policy, without universal automatic termination or removal of unrelated security holds.
- Maker-checker approvals have independent approvers, self/related-party conflict restrictions, versioned thresholds, immutable material request revisions, bounded delegation, validity expiry and formal revocation. Prior-revision approvals do not authorise changed requests; historical valid decisions remain auditable.

### 13.4 Refund settlement, write-offs and accounting periods

- Refund authorisation, submission, provider processing and verified settlement are separate. Unknown/timeout refunds retain reservations and undergo provider reconciliation; no blind duplicate payout. Partial settlement reduces payment coverage only by verified outflow.
- If provider refund outflow exceeds authorised amount, recognise the **full actual outflow**, attribute only authorised amount to the refund, quarantine the excess variance and block further affected refunds until independent review. Unrecoverable variance requires independently authorised loss adjustment and balanced ledger evidence.
- Financial write-offs use versioned accounting/approval policies, independent maker-checker, conflict checks, immutable revisions, approval validity and revocation. Correct posted invalid write-offs with new independently authorised balanced compensating journals, never editing or deleting the original.
- Closed accounting periods stay closed. Corrections post into eligible open periods or follow separately authorised prior-period restatement. Versioned risk-based closure policy blocks mandatory unresolved exceptions; material exceptions discovered later create linked cases and governance review.

### 13.5 PostgreSQL financial security and integration reliability

- Enforce tenant isolation using non-null tenant IDs, `(tenant_id,id)` parent uniqueness and composite tenant-scoped foreign keys across membership and financial relations. RLS is defence-in-depth, not a substitute for verified authorisation.
- Effective membership intervals use PostgreSQL half-open `tstzrange` with an unbounded lifetime upper end and GiST exclusion preventing overlap. Historical entitlement events remain immutable and separate from effective projections.
- Protected database-side posting validates balanced double-entry totals, currency, tenant, period and idempotency. All journal-line changes lock the journal header and are forbidden after posting; Laravel runtime cannot bypass guards.
- Separate restricted runtime, migration, protected financial owner, issuer and exceptional maintenance roles. Privileged operations require independently issued, narrow, short-lived, actor/tenant/target-bound authorisations; unverified caller UUIDs or session variables cannot establish authority. Issuer outages fail closed with safe evidence intake and reconciled recovery, never an emergency Laravel bypass.
- Use durable tenant-scoped operation idempotency with immutable fingerprints, invariant-specific locking, selective PostgreSQL `SERIALIZABLE`, bounded retry and fresh checks. Do not repeat external provider side effects inside retried DB transactions.
- Use transactional outbox and idempotent inbox for at-least-once event delivery, consumer deduplication, ordering, retry/dead-letter handling and reconciliation. Do not claim external exactly-once messaging.
- Use expand–migrate–contract evolution with staged validation, controlled backfills, tested backups, monitored integrity and explicit recovery plans; protect financial invariants during migration.

### 13.6 Backup, privacy-safe restoration and independent evidence

- Encrypted backups, WAL/PITR or equivalent, protected off-site copies, restore drills, versioned retention and separate deletion authority are required. Centrally hosted production engineering targets are **RPO ≤15 minutes** and **RTO ≤4 hours**, subject to validation, not guarantees. Self-hosted Pro defines deployment-specific objectives and responsibilities; financial safeguards remain mandatory.
- A restore that may have lost committed financial state enters restricted quarantine, reconciles provider settlements/operation identifiers, reconstructs only through authorised idempotent operations and requires independent release approval. Never replay a refund solely because its database record is missing.
- Restore privacy and identity state from protected post-backup evidence: account-link revocations, identity corrections, security holds, consent, visibility and lawful deletion. Never automatically republish a profile or restore revoked privileges.
- Preserve tamper-evident critical-decision evidence outside primary PostgreSQL failure domain with stable event IDs, ordering, provenance and controlled access. Monitor asynchronous lag, delivery gaps and uncertain completeness; fail closed when critical post-backup decisions cannot be verified.
- Designated high-risk ownership, security and privileged financial decisions require independently durable evidence acknowledgement before **new privileges** or protected financial execution. Restrictive security actions take effect immediately locally even while acknowledgement is pending. Lower-risk events may replicate asynchronously with monitoring.
- Pending high-risk privilege-granting decisions remain non-effective during evidence outages. Use idempotent retries, escalation, expiry/cancellation and independently authorised recovery; no timeout or administrator bypass. Revalidate identity, authority, policy, security epochs and financial state before delayed finalisation; late acknowledgement cannot revive cancelled decisions.

### 13.7 Remaining design gates and implementation boundary

Before physical migrations, review the precise canonical membership authority representation; first-activation transaction and evidence boundary; entitlement projection and exclusion DDL; financial issuer trust protocol; payment/refund/ledger operation contracts; risk-tiered evidence classification; currency precision and rounding; policy schemas; PostgreSQL roles/RLS; and independent evidence storage/recovery procedures. These are **unresolved physical design gates**, not reversals of the 88 approved decisions. The Step 34 planning review addresses them separately.

**Additional unresolved Step 34 physical-design dependencies:** Protected expiring financial reservations and deadline-safe consumption; unknown-outcome reconciliation; immutable financial evidence and independent determinations; authority epochs and concurrent invalidation; complete direct and indirect financial dependencies; bounded fail-closed quarantine; independently authorised atomic exact-scope release; and recovery integrity. These remain subject to P1–P8, all `unproven`.

**Documentation-only status:** This register does not authorise source changes, migrations, database operations, scripts, Git commits or pushes. Sections 1–12, including the original operational principles, remain applicable except where expressly superseded above.

## 14. Step 34 — Unified Architecture Decisions and Conditional Sign-off

**Planning status:** Step 34.A1–A34 approved in principle and conditionally signed off.

**Implementation status:** Not approved. PostgreSQL technical proof gates P1–P8 remain unresolved.

This section extends the approved Steps 32–33 decisions in Section 13 and the detailed database architecture in `docs/database/DATABASE_ARCHITECTURE.md`, Sections 10–11. It does not replace the existing product goals, deployment boundaries, release priorities, privacy controls or organisation autonomy.

### 14.1 Identity, affiliation and authoritative membership — A1–A5

FILMUNITY preserves a global human identity independently of association affiliation and formal membership.

An association's persistent original-person affiliation remains distinct from the canonical-person authority used to resolve current formal membership.

Only one current authoritative formal membership per `(tenant_id, canonical_person_id)` may confer membership-derived privileges. Historical and superseded memberships remain auditable but non-authoritative.

Application approval creates or advances a pending activation operation, not permanent membership. First activation requires valid independently acknowledged evidence and all applicable eligibility, financial, affiliation, standing, policy and security requirements.

Activation operations have immutable revisions, protected concurrency and idempotency controls, and at most one current nonterminal operation per tenant and canonical person. Incompatible operations require authorised supersession.

### 14.2 Activation deadlines, invalidity and replacement — A6–A13

Activation follows explicit pending, acknowledgement, revalidation and finalisation states, with irreversible terminal outcomes.

Revision expiry and evidence-acknowledgement validity are separate immutable windows. The earliest applicable deadline governs.

The architecture requires strict deadline-safe activation: a transaction that commits after the governing deadline must not confer valid activation, even if it began before expiry.

The PostgreSQL protocol proving this requirement remains unresolved under P1. A pre-commit timestamp check is not, by itself, sufficient proof.

Membership access must fail closed unless a durable, independently verifiable valid finalisation outcome exists.

An invalid committed activation is an integrity incident. Its membership record and retired number remain preserved and non-authoritative. Replacement requires a new UUID, new number, fresh evidence and separate financial reassessment.

Previously verified funds may be credited or reallocated only through independently authorised, auditable financial procedures. Payment history does not automatically establish replacement fee fulfilment.

### 14.3 Financial reservations, evidence and determinations — A14–A24

Financial reservations protect verified eligible funds against competing claims. They are bound to exact operations, assessments, amounts, currencies, policy revisions and authority. A reservation is neither a payment nor fee fulfilment.

Reservation consumption must satisfy strict deadline-safe durability requirements. PostgreSQL correctness remains unresolved under P2.

Unknown consumption outcomes remain under reconciliation and cannot release funds for competing claims merely because of a timeout. Exceptional resolution requires independent approval, balanced accounting and continued duplicate-claim protection.

Late evidence creates a linked reconciliation case and, where appropriate, separately authorised compensating accounting.

Financial evidence is registered immutably using trusted source identity, event identifiers, transaction references, fingerprints and provenance. Duplicate identical events are idempotent; conflicting events are quarantined and independently investigated.

Material conflict determinations require independent, conflict-free approvals and immutable revisions. Ordinary reviewer resignation does not invalidate historically valid approval, but fraudulent, revoked, expired or otherwise invalid authority cannot support new financial reliance.

Later-discovered invalid authority blocks new reliance immediately. Completed journals remain preserved, with corrections performed through authorised compensating entries rather than destructive changes.

### 14.4 Authority dependencies, quarantine and release — A25–A30

Protected financial execution must establish current authority and a complete, verifiable dependency chain.

The architecture requires concurrency-safe ordering between authority invalidation and financial reliance. Stale authority must fail closed, and unknown outcomes require reconciliation.

Direct and indirect dependencies must be recorded before finalisation. Missing, contradictory or unverifiable dependencies block affected operations.

Containment should be limited to the smallest demonstrably safe scope. Where that cannot be established, the system must escalate to a broader financial-domain or tenant restriction.

Quarantine release requires independently authorised, current, exact-scope approval, verified evidence and revalidation of authority, security and financial exposure. Release must be atomic and idempotent for the complete approved scope.

Historical journals and evidence must remain intact. Reconstruction cannot fabricate missing authority or silently remove security restrictions.

These are approved logical requirements. Their PostgreSQL enforcement, concurrency and recovery correctness remain subject to P3–P8.

### 14.5 PostgreSQL proof gates and implementation readiness — A31

The eight technical gates are:

| Gate | Required acceptance | Current status |
|---|---|---|
| P1 | Deadline-safe first activation and permanent membership identity | `unproven` |
| P2 | Deadline-safe reservation consumption | `unproven` |
| P3 | Concurrent authority invalidation and financial reliance | `unproven` |
| P4 | Complete financial authority dependencies | `unproven` |
| P5 | Bounded fail-closed containment | `unproven` |
| P6 | Atomic independently authorised quarantine release | `unproven` |
| P7 | Protected financial execution, ledger integrity and idempotency | `unproven` |
| P8 | Recovery integrity and restored restrictions | `unproven` |

Each gate requires a documented PostgreSQL protocol, explicit invariants, appropriate adversarial concurrency and crash/recovery tests, and independent technical acceptance.

Permitted statuses are `unproven`, `under_review`, `accepted` and `rejected`.

Conditional logical architecture approval does not change a proof-gate status. Dependent production capabilities remain blocked until their applicable gates are accepted.

Release planning must separate:

1. Logical architecture approval.
2. Physical design review.
3. PostgreSQL proof-gate acceptance.
4. Implementation and integration testing.
5. Operational and security readiness.
6. Separately authorised production enablement.

Passing one stage does not automatically authorise the next.

### 14.6 Apache-2.0 foundation and optional capabilities — A32

FILMUNITY uses three architectural classifications.

**Class 1 — Mandatory Apache-2.0 foundation:** Existing authentication integration, global identities, organisations, tenant isolation, security, authorisation, audit and required shared infrastructure.

**Class 2 — Capability-dependent Apache-2.0 modules:** Association membership, financial assessments, protected accounting, reconciliation, title registration and other enabled capabilities. Their complete security, evidence, recovery and financial dependencies must be present whenever the relevant capability is enabled.

**Class 3 — Optional separately licensed PRO extensions:** Additional proprietary modules or services that do not restrict Apache-2.0 core rights or become hidden prerequisites for core operation.

Module retirement, disabling or licence changes must not erase protected historical records, bypass retention, invalidate accounting evidence or evade unresolved security obligations.

Independent self-hosted deployments retain their own authentication, storage, secrets, database and recovery responsibilities. Central federation is not implied.

### 14.7 Cross-module consistency and contradiction register — A33

The final consolidated architecture must preserve consistency across:

- Global identity, canonical identity and tenant affiliations.
- Membership applications, first activation, standing and entitlements.
- Fee obligations, verified funds, reservations and accounting.
- Financial evidence, independent approvals and protected authority.
- Authority invalidation, dependency completeness and containment.
- Quarantine release, recovery and privacy/security restoration.
- Mandatory open-source capabilities and optional proprietary extensions.

**Reconciliation register**

| ID | Required clarification | Current disposition |
|---|---|---|
| CR-01 | Historical membership records versus single authoritative membership | Documentation reconciled; physical enforcement unproven |
| CR-02 | Original-person affiliation versus canonical-person authority | Original-person and canonical authority clarified; physical enforcement pending |
| CR-03 | Atomic activation wording versus strict deadline-safe commit requirements | Documentation wording qualified; P1 remains `unproven` |
| CR-04 | Financial and recovery summaries versus complete dependency and quarantine architecture | Dependencies cross-referenced; P2–P8 remain `unproven` |

Every material contradiction must be recorded with its source, affected decisions, impact, proposed resolution and approval outcome. No material architectural assumption may be silently changed.

The contradiction register is a required governance artifact; its inclusion does not imply an independently completed consistency audit.

### 14.8 Conditional logical sign-off and change control — A34

FILMUNITY Step 34.A1–A34 is conditionally signed off as the completed logical architecture planning phase.

The sign-off confirms approved logical responsibilities, security and financial invariants, cross-module consistency requirements, traceability expectations and a defined PostgreSQL proof-gate process.

It does not constitute approval of a physical schema, SQL constraints, migrations, implementation, deployment or production operation.

P1–P8 remain unresolved until independently evidenced and accepted. Strict commit-time activation and reservation-consumption safety are outstanding technical blockers.

Material architectural amendments require versioned explicit reapproval, with updated decision traceability, affected invariants, contradiction records and proof-gate impacts.

**Documentation-only boundary:** No source code, scripts, migrations, database operations, production configuration or Git operations are authorised by this section. Any documentation update requires separate explicit authorisation.