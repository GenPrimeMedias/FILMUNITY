# FILMUNITY — Intended Workflow, Implementation Checkpoint and Development Handoff

**Prepared:** 21 November 2026, India time  
**Purpose:** Upload this document with the current project source and latest patch in the next chat so development can continue without repeating or overwriting completed work.

## 1. Read this first — exact continuation point

FILMUNITY is an existing, working Laravel/PostgreSQL + React/TypeScript/Inertia application. Continue it; do not rebuild it in Next.js or replace its identity, tenancy, UI or accounting engine.

The latest **user-confirmed working checkpoint** is:

- Individual identity/profile and association registration/membership workflows.
- Manual membership payments, activation, receipts/cards, validity and renewals.
- Association-specific membership numbering and custom plans.
- Financial accounting foundation, ledgers, invoices/bills, settlements, ordinary credit/debit notes, GST/TDS configuration and **multi-line tax-aware billing**.
- Correct GST/TDS sidebar selection and consistent olive-green/dark UI.
- **164 Laravel tests passed, 2,016 assertions**, reported by the user, duration 24.80 seconds.
- **87 isolated PostgreSQL accounting/tax checks**, **9 PHP calculation checks**, **17 help-merge checks** and **12 sidebar-selection checks** passed in the user's Windows environment.
- Finance JSON export schema at this checkpoint: **`filmunity-association-finance/v6`**.

### Newer work exists, but its installation is NOT confirmed

In a newer chat, a full source archive named **`FILMUNITY.zip`** was uploaded and a patch named **`FILMUNITY-tax-correction-notes.patch`** was prepared. Its contents were read for this handoff. It adds tax-aware credit/debit corrections and migration:

`database/migrations/2026_11_21_020000_add_tax_correction_notes.php`

The user then asked how to execute that patch. **No successful installation, migration, build or post-patch test output has been provided in this conversation.** Do not report tax-aware corrections as installed, verified or production-ready yet.

**Immediate next task:** inspect the actual current checkout and that patch, determine whether it is unapplied/partially applied/already applied, review its accounting/reporting consequences, then help apply or repair it safely and run the guarded test suite. Avoid independently generating a second competing implementation before inspecting the existing patch.

This handoff describes confirmed history and a source-reviewed checkpoint, not a live audit of the user's Windows database. The actual latest source, lockfiles, migration status and test output take precedence.

## 2. Product purpose and intended scope

FILMUNITY, formerly UNI360, is an **open-source association and union management/community platform for the South Indian film industry**, including Tamil, Malayalam, Telugu and Kannada communities. The OTT application EIGHT TOO is a separate proprietary project; do not import OTT/FFmpeg/HLS/advertising/subscription requirements into FILMUNITY.

Intended model:

| Audience / deployment | Intended experience | Checkpoint |
|---|---|---|
| Individuals | Free FILMUNITY identity, professional portfolio/public profile, association applications and memberships | Core flows implemented |
| Associations/unions | Register the organization, receive platform approval, configure branding/plans/forms, manage members and accounts | Core administration and finance implemented |
| Central hosting | One underlying identity system, multiple independent association workspaces | Existing association-scoped architecture in use |
| Pro/self-hosted | Organization's own hosting/domain/branding while preserving identity/domain boundaries | Product direction; full packaging, licensing and deployment enforcement not confirmed |
| Platform administrator | Review association registrations, inspect registered accounts and relevant payment/application evidence | Review/admin views implemented |
| Film producers' associations | Optional independent movie-title registration module | Intended later module; not confirmed implemented |

Historical repository reference: `https://github.com/GenPrimeMedias/UNI360.git`. The product/project has since been renamed FILMUNITY. **Inspect `git remote -v` and branches before using any remote; do not assume that historical URL is the current source of truth or contains the latest local patches.**

The source declares Apache-2.0 licensing. Open-source release readiness still needs a final source/license/security/CI/documentation audit.

## 3. Existing stack, architecture and environment

| Layer | Current direction / evidence |
|---|---|
| Backend | Laravel 13 application; user previously reported 13.39.0. Reviewed composer constraint is `laravel/framework: ^13.17`. Verify installed lockfile version. |
| PHP | User runtime PHP 8.3.29, 64-bit; BCMath and PDO PostgreSQL verified. XMLWriter needed for future Tally XML. |
| Frontend | React + TypeScript + Inertia.js 3 + Tailwind CSS 4; existing components and layouts. Reviewed React dependency constraint is `^19.2.0`; use the actual lockfile version. |
| Build | Vite/Vite Plus; `npm run build` invokes `vp build`; `npx tsc --noEmit` checks types. Laravel Wayfinder participates in build generation. |
| Database | PostgreSQL; user previously reported PostgreSQL 18.1. Tests use real isolated `filmunity_test`, not the application database. |
| Authentication | Existing Laravel/Fortify/session flow plus Socialite Google sign-in; verified-email controls. |
| QR | Existing membership/public-profile QR implementation; do not create a competing identity system. |
| Windows checkout | `D:\FILMUNITY\FILMUNITY` |
| Local browser example | `http://localhost:8003` |
| Historical chat scratch | A separate temporary assistant workspace; its availability does not close or modify the Windows project. |

### Architecture constraints

- Extend the existing modular application and approved-association scope. Do not introduce a parallel `organizations`/`ledgers`/`vouchers` domain merely to match a generic specification.
- The existing tenant identifier is the UUID of **`association_registration_applications.id`**, with the representative identified by `applicant_id` and approved status controlling administration.
- Keep one underlying user identity, with separate individual identity, representative privileges, association membership and financial records.
- Authorization must be enforced server-side and through navigation; a hidden link alone is insufficient.
- Finance operations use PostgreSQL transactions, association-row locks, exact money arithmetic, immutable posted records and audit history.
- Existing canonical money lines use integer paise, with generated `NUMERIC(15,2)` debit/credit columns and exact tax/allocation decimal fields. No `FLOAT` or JavaScript floating-point monetary arithmetic.
- All active version/branch/schema assumptions must be checked against the newest checkout before patching.

## 4. Intended and implemented individual workflow

### 4.1 Registration and platform identity

1. An individual signs up using their email and completes verification, or uses the existing Google sign-in integration.
2. After verified entry, FILMUNITY ensures a platform membership identity.
3. The identity number uses **`FU-` plus ten digits**, for example `FU-0000000001`.
4. This free platform identity is separate from any association's paid membership and its numbering scheme.
5. Dashboard displays profile summary/completion and FILMUNITY membership information.

Implemented support includes `IndividualMembership`, `filmunity_individual_memberships`, card tokens and existing authentication tests. The platform identity's displayed active status is not an association-admin entitlement.

### 4.2 Professional profile and public showcase

The user can build a resumable professional profile/wizard containing industry/basic details, images, work biography, portfolio and social/public links, including a passport photo for ID-card use. Profile completion/publication controls govern the public showcase.

Public route: **`/community/@{username}`**. Public snapshots/publication updates are intentionally separate from editable drafts. The user's accepted UI includes profile-summary, About, selected-work and professional-detail sections, consistent olive top borders, editing actions and publish/unpublish/share controls.

Dashboard/card features include compact digital membership cards, QR-based live validity verification and public profile URL/QR sharing. Do not imply that the identity-verification QR and public-profile QR have the same purpose.

### 4.3 Applying for association membership

The final intended sequence, after earlier requirement changes, is:

1. Open the separate **Apply for Membership** page.
2. Choose an approved, available association and submit its configured form and required documents/acknowledgement.
3. Association admin reviews the application and accepts or declines it, with decision notes.
4. Once accepted, the applicant confirms an available association membership plan.
5. Association issues its configured payment request/instructions for the confirmed plan.
6. Applicant pays externally and uploads proof/reference.
7. Association reviews the proof. Payment acknowledgement activates membership and issues/updates receipt, membership number, validity and card.
8. The member can access the corresponding association workspace and their membership/payment/renewal history as authorized.

**Important:** The early design mentioned selecting membership type during submission; the later accepted requirement moved final plan confirmation after association approval. Preserve the current implemented `confirm-plan` flow and snapshots; inspect the latest source before altering whether a form also captures an initial preference.

The user may apply to multiple approved associations, but cannot create duplicate concurrent/active membership flows for the same association. Declines/history must be retained; determine retry eligibility from the current application/membership state rather than deleting old applications.

### 4.4 Individual sidebar responsibilities

The member-oriented navigation includes:

- Dashboard.
- My Memberships — membership application history.
- Apply for Membership — separate application page.
- Memberships and renewals — member-side validity/renewal access.
- My payments.
- Professional profile.
- My association workspaces — association access where eligible.

Some member-side lists share underlying membership components with different entry context. Action wrapping was cleaned up; preserve the compact presentation.

## 5. Association representative, platform review and workspace setup

### 5.1 Registration and review

A representative signs up and submits association registration details, official contact, registration certificate/evidence, organization/industry/location data and representative role. Platform reviewers use the association review queue to approve/decline, retain notes and access documents.

Registration history now uses the same listing language as registered accounts: search, sort, pagination and one expanded row, with submitted data/documents/decisions. Approved registrations expose the association workspace.

A later registration can be submitted only when allowed by the existing declined/pending/approved logic. Do not weaken it casually.

### 5.2 Representative restrictions

A representative with a **pending or approved association registration** is treated differently from a normal individual applicant. The representative retains a FILMUNITY identity and professional profile, but cannot use personal association application/payment/renewal workflows. Those routes are restricted server-side as well as hidden in navigation.

Being a representative **does not automatically prove a paid association membership or create a paid member ledger**. Do not create false memberships or backdated payments merely to support navigation. Existing historical memberships/data must not be deleted when applying representative restrictions.

The official association email may equal the representative's signup email; the earlier requirement that they differ was removed.

### 5.3 Association-admin sidebar groups

| Section | Links |
|---|---|
| Representative profile | Dashboard; Professional profile |
| Association Management | Association dashboard; Workspace setup |
| Members Management | Membership applications; **Application Payments**; Members; **Members renewal** |
| Financial Management | Finance overview; Invoices and settlements; GST / TDS billing; Credit and debit notes; Vouchers; Accounts; Reports; Ledger; Settings |

Member-side “Memberships and renewals” remains distinct from association-admin “Members renewal.” The renamed **Application Payments** area concerns membership application/renewal collections, not the association's complete bookkeeping system.

Each major section has contextual help access. Tax billing has its own explicit active-route selection; it must not highlight Finance overview.

### 5.4 Workspace configuration

Implemented setup includes:

- Full-width Workspace branding section, then Membership form fields underneath.
- Association display branding/accent/logo and official contact email.
- Required baseline **Yearly and Life-Time** plans and their distinct fees, plus association-created custom membership plans.
- Plan/fee snapshots for submitted/confirmed/requested records so later configuration changes do not rewrite history.
- Custom member form fields, including acknowledgement descriptions rather than an unexplained blank “I Agree.”
- Form preview and existing validation/upload limits.
- Manual payment instructions used in membership payment requests.
- Custom membership-number prefix, next number and padding.

Reviewed logo validation: square image, **512×512 minimum, 2048×2048 maximum, maximum 1 MB**, PNG/JPEG/WebP as accepted by the controller. Logos should fit inside their box using contain behavior; receipt/print branding uses a larger logo before the association name, while lists use mini logos. Do not force one display size everywhere.

## 6. Membership payments, receipts, cards and renewals

### 6.1 Manual collection workflow — confirmed implemented

Application acceptance is not the same as payment acknowledgement. Payment requests, proof submissions and final acknowledgement have their own state/history.

- Applicant confirms plan.
- Association issues request using snapshotted plan, fee and instructions.
- Member submits reference/date/proof.
- Admin reviews and may require correction/reject proof or acknowledge receipt, according to current state rules.
- Acknowledgement issues receipt and membership activation/term; repeated actions must not duplicate records/numbers.
- Existing zero-fee acknowledgement path is supported and should remain authorized/audited.
- Member/admin listings, private proof downloads and payment emails/status notifications reflect the flow.

Receipts have association branding and sections, print/save/download navigation and close controls. Digital cards are compact, include passport image where available, association/platform identity as appropriate, membership number and QR verification. Verification must reflect current status, not treat a screenshot as permanent proof.

A downloadable/printable artifact existing today does not imply that every financial document has a complete statutory PDF issuance system.

### 6.2 Numbering

Platform: `FU-` + ten digits.

Association membership: configurable prefix + next numeric sequence + zero-padding, for example `SISA-6000325`, `TFPC-9623`, or bare `1000`.

Configuration is intended to set the **next number to issue**, not to overwrite the last number already issued. The implementation uses association locking, does not allow moving the sequence backwards, rejects collisions and retains numbering events. Existing numbers are not renumbered after a prefix change.

For a future import, preserve external member IDs/numbers explicitly, resolve collisions and reconcile the sequence before enabling new issuance. Numbering support does not mean the legacy import module already exists.

### 6.3 Validity and renewals

- Yearly renewal eligibility opens **30 days before expiry**; expiry and current status are derived from the term and current time.
- Life-Time memberships do not expire annually.
- Early renewal adds a year from existing expiry; after-expiry renewal begins from acknowledgement according to the accepted flow. Keep date/timezone behavior under tests.
- Open renewal requests are checked to prevent duplicate pending collection flows.
- Validity/term/receipt histories remain available.
- Association-admin **Members renewal** is the due/expiring renewal management queue, rather than an unfiltered copy of all Members. Inspect current overdue inclusion/filter semantics before changing them.
- Manual renewal reminder action and reminder records/email handling exist. Fully automated recurring billing/reminder schedules are not yet confirmed.

## 7. Communication — actual behavior versus intended outcome

Intended: application decisions, payment requests/submissions/acknowledgements and renewal activity appear in the relevant workspaces and produce traceable member/association email communication.

Implemented support includes `ApplicationCommunication`, `AssociationCorrespondence`, `PaymentNotification`, `MembershipReminder`, correspondence/notification persistence and retry paths.

**Actual reviewed sender behavior:** email uses the platform's configured mail-from address, branded as the association “via FILMUNITY,” with the association official address or applicable supplied address as Reply-To. This is not the same as authenticated sending from every association's mailbox. Do not claim per-association SMTP/OAuth sending is implemented.

Official email is configurable in Workspace setup and may match the representative's email. Association branding is reused in member-related communications/receipts. Real delivery still depends on mail configuration, domain authentication and delivery processing.

An existing command `php artisan filmunity:deliver-payment-mail` retries pending payment notifications. The reviewed `routes/console.php` did not establish an automated schedule for it. Verify production scheduler/worker wiring before claiming unattended delivery or reminders.

## 8. Shared UI and usability contract

Preserve the accepted cinematic/dark green theme, olive/yellow-green top accents and primary actions. The project has appearance settings; do not redesign it during accounting work.

Shared record listings should provide search, meaningful sortable fields, pagination, one expanded row at a time, readable details, document actions and decision notes. Standard components/patterns include `RecordsTable`, `RecordsPagination`, `SearchableSelect`, `useLiveFilters`, shared dialogs and workflow breadcrumbs.

Financial searches filter automatically rather than requiring repeated Search clicks. Growing account/member/vendor selections are searchable by displayed code/name/reference. Selection submits an explicit ID, not arbitrary typed text. Preserve keyboard/focus behavior, modal portals and the ability to create a ledger without losing a voucher draft.

Vouchers use full-screen, sectioned entry with visible close X. Records/receipts use bordered sections and professional navigation. Success/failure/status feedback must not disappear silently after a POST. Active badges/yearly pills follow the olive accent style.

The earlier stray `Y`/`S` rows were alphabetical grouping labels. The accepted later listing design removed that clutter; do not reintroduce it.

## 9. Financial Management — intended business workflow

The goal is a familiar **Tally-style accounting experience inside FILMUNITY**, without replacing the existing stack or pretending to implement all Tally/statutory features at once.

Typical flow:

1. Initialize association books with the correct start date.
2. Configure/group accounts and sync member/vendor party ledgers.
3. Enter reviewed opening balances through balanced opening entries.
4. Create member invoices/vendor bills and record money receipts/payments, transfers or journals.
5. Save drafts, submit, review and post.
6. Allocate receipts/payments/credits to bills to track exact dues and unapplied balances.
7. Review statements, aging, trial balance, income/expenditure and financial position.
8. Record corrections through traceable notes/reversals; never rewrite posted history.
9. Reconcile cash/bank/tax/source collections, close periods, and export consistent records.

Representative approval roles are presently association-owner based. A complete accountant/treasurer/approver segregation-of-duties/RBAC module is not confirmed; do not market the current owner review as a fully independent maker-checker system.

### 9.1 Accounting foundation — implemented

- Nested account groups with asset/liability/income/expense nature.
- Standard funds, debtors/creditors, bank/cash, subscription income, expenses and tax groupings.
- Linked member and vendor ledgers; member sync and vendor creation.
- Association/financial-year/type-based voucher counters.
- Balanced generic receipt/payment/contra/journal/opening entries and source membership collection accounting.
- Full-screen keyboard-first entry: F4 Contra, F5 Payment, F6 Receipt, F7 Journal, supported invoice/bill entry navigation, Alt+C ledger creation, Enter traversal and Ctrl+A draft save as implemented. Inspect current hook before changing shortcuts.
- BCMath/BigInt exact arithmetic, database balance/immutability guards, association locks and period restrictions.
- Financial reports/export, ledger and clearance/reconciliation fields available through the existing finance controller/UI.

### 9.2 Invoices, bills and settlements — implemented

- Member receivable invoices and vendor payable bills, each tied to one party.
- Receipt/payment settlement sources, partial allocations, advances and remaining balances derived from records.
- No over-allocation, cross-party/tenant allocation or allocation before document dates.
- Allocation release needs a reason; immutable history and closed-period restrictions remain.
- Aging reports and source membership collection reclassification bridge.
- The bridge reclassifies an existing posted membership collection to the member ledger **without creating cash a second time or recognizing income twice**.
- Posted source/bridge relationships and active allocations restrict reversal order.

### 9.3 Ordinary accounting credit/debit notes — implemented

- Member credit note reduces a posted ordinary invoice.
- Vendor debit note reduces a posted ordinary bill.
- Original party/counter and correction reason retained; review/post/reverse lifecycle.
- Posted unreversed correction totals cannot exceed the original face value; posting rechecks under a lock. Drafts do not reserve capacity.
- Credits against a paid original may be applied to a later same-party bill through the existing registry.
- No cash moves merely by issuing a note; a refund is a separate workflow, not implicitly completed.
- Before the newer pending tax-correction patch, simple notes explicitly rejected tax-aware originals.

### 9.4 GST/TDS configuration — implemented, manually reviewed applicability

The tax settings contain legal identity/address/state, GST registration/GSTIN, PAN/TAN, effective date, TDS enablement and policy/review notes.

The New tax-aware invoice/bill button is intentionally disabled until a tax profile is saved. GST/TDS checkboxes need not be enabled merely to configure a no-tax association. Complete required identity, address, notes and confirmation first.

Tax calculations support tax-exclusive amounts, reviewed exempt/non-GST treatment, CGST+SGST, CGST+UTGST, IGST and no-GST mode. Registration/state/place-of-supply, HSN/SAC, PAN/TAN and reviewed TDS-reference/date checks apply.

Vendor GST goes to input control accounts only when the reviewed option is chosen; otherwise it forms part of expense/asset cost. That classification does not certify eligibility or match portal ITC.

Positive vendor TDS splits net vendor payable and a separate TDS liability account. The current software checks section-393-style references for events from 1 April 2026, and legacy references for older events. This is a recorded/reviewed reference check, not exhaustive legal table/rate validation. Vendor earlier-of-credit/payment dates preceding the bill date require separate reconciliation; the current form does not silently handle advances or duplicate prior deductions.

**No automatic RWA ₹7,500 exemption applies to film associations.** Do not enable a generic threshold engine without current legal/applicability review. A pass-through label by itself does not establish exemption.

Snapshots preserve tax identity, rates, branding, treatment, expected ledger lines and calculation. Posting validates frozen totals/entries. Draft tax documents are intentionally frozen; reject/reissue rather than changing history.

The monthly report includes original postings and negative reversals on their book dates. It is a movement report, not final GST return liability, confirmed ITC, challan payment status or TDS certificate.

### 9.5 Multi-line tax-aware billing — implemented and user verified

- 1–20 charges; separate searchable ledger, description, amount, GST treatment/rate, HSN/SAC and exemption/non-GST reason.
- Vendor TDS base per charge, summed for one reviewed document TDS rate/reference.
- One party, GST split/place-of-supply context and input-GST classification choice per document.
- GST rounds per charge and then sums. TDS rounds on the total reviewed deduction base.
- Posting groups identical account amounts and keeps one party line.
- Expanded and branded printable details show frozen charge breakdown.
- Legacy version-1 single-charge documents remain readable and retain their calculations.
- Version-2 headers carry `gst_rate=0` as a compatibility marker; **read item rates, not the header marker, when computing/exporting multi-rate tax**.
- No quantity/unit-price inventory lines or mixed eligible/ineligible input-GST treatment within one bill in this phase.

Illustrative software calculation: ₹100 at 18% + ₹50 at 5% + ₹25 reviewed exempt = ₹195.50 gross/receivable. If a reviewed vendor deduction is ₹10, the bill's net vendor payable is ₹185.50. These examples do not prescribe tax applicability.

### 9.6 Financial features still not confirmed complete

Statutory invoice/e-invoice generation, IRN/portal QR, GST return filing, RCM/cess/import/export/SEZ/composition treatment, automatic TDS threshold/certificate/advance tracking, challan reconciliation, statutory tax certificates, recurring invoices, robust refund workflows, independent finance roles, Tally Prime XML export and legacy XLS imports remain incomplete or future work unless the newest source demonstrates otherwise.

Existing branded print/accounting detail is not a complete statutory invoice/PDF issuance system.

## 10. New tax-correction patch — review before continuing

**Artifact:** `FILMUNITY-tax-correction-notes.patch`, prepared in the newer chat after the confirmed multi-line checkpoint.

Files it changes/adds, based on the patch read for this handoff:

- `app/Http/Controllers/BillSettlementController.php`
- `app/Http/Controllers/CorrectionNoteController.php`
- `app/Services/Accounting/CorrectionNoteService.php`
- **New** `app/Services/Accounting/TaxCorrectionService.php`
- **New** `database/migrations/2026_11_21_020000_add_tax_correction_notes.php`
- `docs/database/DATABASE_ARCHITECTURE.md`
- `resources/js/pages/association-billing.tsx`
- `resources/js/pages/association-tax.tsx`
- `tests/Feature/MultiLineTaxTest.php`

Observed patch design:

- Adds `association_finance_notes.tax_correction` JSON and PostgreSQL planning/validation functions.
- Allows selecting original charge line numbers and partial base reductions, rather than remapping to unrelated ledgers.
- Preserves original references and derives GST/ledger corrections through `filmunity_tax_note_plan` and associated guards.
- Rechecks remaining per-charge correction capacity at posting; retains posted/reversal history.
- Captures explicit reviewed vendor TDS reversal amount/notes rather than assuming TDS is always refundable/reversible.
- Exposes tax source details in the existing corrections UI and audit information.
- Adds seven feature-test methods covering partial GST limits, reviewed vendor TDS, tenant isolation, legacy tax capacity on reversal, ledger tampering, cumulative paise rounding and IGST without input credit.

### Required review/verification checklist

1. Does the latest checkout already contain this patch or the `020000` migration? Compare files and migration status before applying anything.
2. Verify ownership, same-party/original-line restrictions, original dates, open periods and financial-year numbering.
3. Verify cumulative base and tax caps and rounding across several partial notes, including final residual paise.
4. Check exact money transport to TypeScript: the patch contains numeric JSON/type representations; avoid losing paise/precision on large values and do not introduce floating-point calculation paths.
5. Verify input-GST versus expensed-GST correction direction and explicit TDS reversal treatment against deposited/retained deductions.
6. Verify allocation behavior against the original and future same-party bills, paid-original credits, reversal release order and original-note dependencies.
7. Verify both old single-charge and new multi-line records; preserve ordinary non-tax corrections.
8. **Review tax report integration:** the patch file list does not modify the existing `AssociationTaxController` monthly tax-summary query. Do not assume notes automatically appear in that report or final tax totals; inspect/integrate and test the intended reporting treatment.
9. Verify export/audit/print coverage and whether the bundle version must advance from v6 for the new correction snapshot semantics.
10. Run the guarded PostgreSQL/Laravel tests, frontend checks/build and browser smoke tests before declaring the patch complete.

Do not claim successful execution or add its new tests to the verified 164 count until actual results are available. This handoff does not certify the patch's tax/legal correctness.

## 11. Database and code map

### 11.1 Main record families

| Area | Principal tables / source concepts |
|---|---|
| Identity/auth | `users`, sessions/auth tables, Google identity |
| Professional profile | `professional_profile_drafts`, username reservation/public snapshots |
| Platform membership/cards | `filmunity_individual_memberships`, `association_membership_card_tokens`, membership correspondence |
| Association registration | `association_registration_applications`, submitted evidence, official contacts |
| Workspace/plans | `association_workspace_settings`, `association_membership_plans`, configurable form snapshots |
| Membership applications | `association_membership_applications` |
| Manual collection | `association_payment_requests`, `association_payment_submissions`, `association_payment_receipts`, counters/events/notifications |
| Membership/renewal | `association_memberships`, term history, renewal request relationships, reminder records |
| Numbering | `association_membership_numbering`, `association_membership_number_events` |
| Accounting | `association_finance_settings`, `association_finance_accounts`, `association_finance_vouchers`, `association_finance_lines`, `association_finance_events` |
| Masters | `association_account_groups`, `association_finance_vendors`, `association_voucher_counters` |
| Bill settlement | `association_bill_documents`, `association_bill_allocations` |
| Ordinary corrections | `association_finance_notes` |
| Tax | `association_finance_tax_settings`, `association_finance_tax_counters`, `association_finance_tax_documents`, `association_finance_tax_items` |
| Pending patch | New `tax_correction` JSON on finance notes and related SQL functions; installation unconfirmed |

Verify exact term/history table names and newest columns from migrations; the table families above are a navigation aid, not a complete DDL export.

### 11.2 Backend files worth opening first

`app/Support/`:

- `IndividualMembership.php`, `MembershipQr.php`.
- `AssociationWorkspace.php`, `FilmunityNavigation.php`, `FilmunityReviewer.php`.
- `MembershipEligibility.php`, `AssociationMembershipAccess.php`.
- `AssociationMembershipForm.php`, `AssociationPlans.php`, `AssociationMembershipNumber.php`.
- `AssociationPayment.php`, `MembershipLifecycle.php`, `MembershipReminder.php`.
- `AssociationBranding.php`, `AssociationCorrespondence.php`, `ApplicationCommunication.php`, `PaymentNotification.php`.
- `AssociationFinance.php` — core posting, locking, decisions, source collection and audit behavior.

`app/Services/Accounting/`:

- `AccountingMoney.php` — exact decimal/minor-unit conversion.
- `AccountingMasters.php` — groups, party ledgers, FY/type numbers.
- `BillSettlementService.php` — documents, allocations/releases, source bridge.
- `CorrectionNoteService.php` — ordinary note lifecycle; extended by pending tax patch.
- `AssociationTaxService.php` — legacy and multi-line calculations, snapshots/posting creation.
- Pending `TaxCorrectionService.php` — SQL-backed correction planning/validation.

Relevant controllers include professional/public profiles, association registration/workspace setup, membership application/member/payment/renewal/reminder/cards, platform admin/help, and finance/accounting masters/billing/correction/tax controllers.

### 11.3 Frontend/shared UI files

Pages under `resources/js/pages/` include:

`dashboard.tsx`, `association-registration.tsx`, `filmunity-admin.tsx`, `filmunity-admin-members.tsx`, `association-workspaces.tsx`, `association-workspace-setup.tsx`, `membership-apply.tsx`, `membership-applications.tsx`, `association-membership-review.tsx`, `membership-payments.tsx`, `membership-payment.tsx`, `association-members.tsx`, `membership-renewal.tsx`, `association-member-workspace.tsx`, `association-finance.tsx`, `accounting-masters.tsx`, `association-billing.tsx`, `association-tax.tsx`, `association-help.tsx`, authentication/settings pages.

Professional-profile components may be composed through controllers/layouts/shared components rather than a single matching page filename; locate the current implementation instead of inventing a replacement page.

Shared components/hooks:

- `resources/js/components/app-sidebar.tsx`
- `resources/js/components/nav-main.tsx`
- `resources/js/components/workflow-breadcrumbs.tsx`
- `resources/js/components/sidebar-section-help.tsx`
- `resources/js/components/records-table.tsx`
- `resources/js/components/searchable-select.tsx`
- `resources/js/hooks/use-live-filters.ts`
- `resources/js/hooks/use-accounting-hotkeys.ts`
- Existing dialog/quick-ledger components.

Other important files:

- `resources/help/association-admin.json`
- `resources/views/receipts/association-membership.blade.php`
- `resources/views/accounting/tax-document.blade.php`
- `app/Console/Commands/CheckAccountingIntegrity.php`
- `app/Console/Commands/DeliverAssociationPaymentMail.php`

## 12. Routes and navigation checkpoint

Inspect the current `routes/*.php` and `php artisan route:list`; these are representative actual routes, not a promise that every API has a separate REST implementation.

| Area | Routes |
|---|---|
| Dashboard/profile | `/dashboard`; `/professional-profile/draft`; publication endpoints; `/community/@{username}` |
| Google | `/auth/google/redirect`; `/auth/google/callback` |
| Registration | `/association-registration`; `/association-registration/review`; registration certificate/review/approval-email endpoints |
| Platform administration | `/filmunity-admin`; `/filmunity-admin/members`; `/filmunity-admin/payments` |
| Association workspace/setup | `/association-workspaces`; `/association-workspaces/{workspace}`; `/association-workspaces/{workspace}/setup` |
| Apply/history | `/membership-applications/apply`; `/membership-applications`; `/membership-applications/{application}/confirm-plan` |
| Admin applications | `/association-membership-review` and decision endpoint |
| Payments | `/membership-payments`; `/association-payments`; request/submission/proof/review/retry-email endpoints |
| Receipts | `/membership-receipts/{receipt}` |
| Membership/workspace | `/my-association-workspaces`; `/association-members`; `/association-member-workspaces/{membership}` |
| Renewals | `/membership-renewals`; `/membership-renewals/{membership}`; renewal reminder endpoint; admin renewal context/filter |
| Cards/verification | `/membership-cards/filmunity`; `/membership-cards/association/{membership}`; `/membership-cards/passport-photo`; `/verify-membership/{kind}/{token}` |
| Help | `/association-help/{section?}`; section download endpoint |
| Finance entry | `/association-finance`; `/association-finance/{association}` with existing tab context |
| Masters | `/association-finance/{association}/masters` |
| Bills/settlements | `/association-finance/{association}/billing` |
| Corrections | Same billing route with `?scope=corrections`; POST `/billing/notes` and note edit endpoint under finance root |
| Tax | `/association-finance/{association}/tax`; POST `/tax/settings`; POST `/tax/documents`; private detail `/tax/documents/{voucher}` |
| Finance actions | Existing voucher save/decision/evidence, allocation/release, bridge, close, sync, clearance and `/export` routes |
| Health | `/api/v1/health` |

The older reviewed route snapshot contains duplicate dashboard declarations and repeated inclusion of `membership-applications.php`. **Verify whether these still exist in the newly uploaded source**, then clean them up only with route regression checks. Do not assume these observations describe the latest checkout or block the requested accounting patch on an unrelated refactor.

## 13. Migrations — historical sequence and current boundary

Never edit an applied migration to fix a later problem. Use a forward migration. Use `migrate:status` to distinguish present files from actual database application.

### Identity, registration and membership

- `2026_11_17_000001_add_google_id_to_users_table.php`
- `2026_11_17_000002_create_professional_profile_drafts_table.php`
- `2026_11_17_000003_reserve_professional_profile_usernames.php`
- `2026_11_17_000004_add_public_snapshot_to_professional_profile_drafts.php`
- `2026_11_18_120000_create_association_registration_applications_table.php`
- `2026_11_18_161000_add_registration_evidence_to_association_applications.php`
- `2026_11_18_171000_add_official_contacts_to_association_applications.php`
- `2026_11_18_181000_create_association_membership_applications.php`
- `2026_11_18_191000_create_association_workspace_settings.php`
- `2026_11_18_201000_add_association_signup_to_users.php`
- `2026_11_19_030000_add_membership_plans.php`
- `2026_11_19_100000_create_association_payment_workflow.php`
- `2026_11_19_110000_reconcile_membership_instants.php`
- `2026_11_19_130000_add_membership_renewals.php`
- `2026_11_19_160000_add_association_membership_numbering.php`
- `2026_11_19_170000_create_membership_reminders.php`
- `2026_11_20_100000_add_individual_membership_identity.php`
- `2026_11_20_120000_add_association_membership_plans.php`

Framework auth/cache/jobs/passkey/2FA migrations also exist. Two historical plan-related migrations exist; inspect both rather than recreating a duplicate plan table.

### Finance

| Migration | Purpose / status |
|---|---|
| `2026_11_20_150000_create_association_finance.php` | Initial books, accounts, vouchers, exact lines, events and balance/immutability guards |
| `2026_11_20_200000_add_accounting_masters.php` | Groups, party masters/FY counters and generated decimal columns |
| `2026_11_20_210000_add_bill_settlements.php` | Bill registry, allocations, bridge and associated guards |
| `2026_11_20_220000_fix_bill_reversal_guard.php` | Forward fix for PL/pgSQL ambiguous alias/record error |
| `2026_11_20_230000_add_financial_correction_notes.php` | Ordinary credit/debit note references/guards |
| `2026_11_21_000000_add_association_tax_billing.php` | Tax settings/counters/documents and frozen tax posting |
| `2026_11_21_010000_add_multiline_tax_billing.php` | Versioned header check, charge snapshots and guards; latest user-confirmed feature checkpoint |
| `2026_11_21_020000_add_tax_correction_notes.php` | Newer patch artifact; application/test status unconfirmed |

Financial down migrations intentionally retain history or refuse destructive rollback. A generic rollback is not a reliable recovery plan after these features are used. Keep coordinated source/database backups and use reviewed forward repairs.

## 14. Verified tests and safe validation procedure

### 14.1 Last user-provided results

| Check | Result |
|---|---|
| Full Laravel suite | **164 passed; 2,016 assertions; 24.80 seconds** |
| PostgreSQL accounting/tax harness | **87 passed** |
| Actual PHP calculation helper | **9 passed** |
| Help merge helper | **17 passed** |
| Sidebar active selection | **12 passed** |

These results belong to the multi-line checkpoint, **before confirmed installation of the newer tax-correction patch**. Do not extrapolate them into a new post-patch total.

Earlier assistant-side checks used PGlite, PHP WebAssembly and TypeScript. Those were isolated checks, not substitutes for the user's real PostgreSQL/Laravel suite. The user subsequently ran and reported the results above.

### 14.2 Test database identity

The guarded test target reported by the user:

- Environment `testing`.
- Host `127.0.0.1`, port `5432`.
- Database **`filmunity_test`**.
- Role **`filmunity`**.

Do not point transactional test helpers at the application database. Do not use `migrate:fresh`, truncate, or reset the user's books to obtain green tests.

### 14.3 Safe preflight in PowerShell

Run from the actual project root. Commands below are checks, not an instruction to apply an unreviewed patch automatically.

```powershell
Set-Location 'D:\FILMUNITY\FILMUNITY'
git status --short
php artisan --version
php artisan migrate:status
php artisan route:list
$env:FILMUNITY_TEST_DB_HOST = '127.0.0.1'
$env:FILMUNITY_TEST_DB_PORT = '5432'
$env:FILMUNITY_TEST_DB_USER = 'filmunity'
.\vendor\bin\phpunit.bat --filter=TestDatabaseConfigurationTest
if ($LASTEXITCODE -ne 0) { throw 'Test database preflight failed.' }
```

For the existing diff patch, use a real patch-file path and inspect before mutation:

```powershell
$PatchFile = Read-Host 'Full path to FILMUNITY-tax-correction-notes.patch'
git apply --stat $PatchFile
git apply --check $PatchFile
```

If the check fails, investigate baseline changes/already-applied status and conflicts. Do not add `--reject`, force overwrite files or discard uncommitted work as a shortcut. Preserve current source and database backups. Only after review apply the patch and its exact new migration to the intended environment, plus the isolated test database through the established guarded procedure. A `.patch` file is not a PowerShell installer or automatically executable migration.

After implementation/migration, run appropriate checks with fail-fast handling:

```powershell
npx tsc --noEmit
if ($LASTEXITCODE -ne 0) { throw 'TypeScript failed.' }
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Build failed.' }
$Suites = @('MultiLineTaxTest','AssociationTaxTest','CorrectionNoteTest','BillSettlementTest','AssociationFinanceTest','AssociationHelpTest')
foreach ($Suite in $Suites) {
    .\vendor\bin\phpunit.bat --filter=$Suite
    if ($LASTEXITCODE -ne 0) { throw "Failed: $Suite" }
}
php artisan test
if ($LASTEXITCODE -ne 0) { throw 'Full suite failed.' }
```

Broaden to membership/payment/renewal, authorization and source collection tests when changes touch their boundaries. Preserve previously passed checks; do not delete tests merely to accept a new feature.

### 14.4 Accounting integrity command and earlier error

`php artisan accounting:check <actual-approved-association-UUID>` checks a specific association in the currently configured database.

The user's earlier **“Finance is not initialized for this association”** command result was not a failing PHPUnit result. The tax page itself had already passed initialization checks. The advice was to verify the actual association UUID from `/association-finance/{UUID}/tax` and that the CLI database matches the browser app, rather than using a placeholder/other tenant.

Do not resolve that message by resetting or reinitializing existing books. Use the real UUID and inspect environment/configuration. Passwords/secrets should never be pasted into a handoff or log.

## 15. Known resolved issues — do not reintroduce

| Issue | Accepted correction |
|---|---|
| Wrong frontend stack requested by mistake | Keep React/TypeScript/Inertia; no Next.js migration |
| Stray Y/S rows | Removed alphabetical grouping clutter in accepted listings |
| “My applications” naming | Renamed My Memberships |
| Apply section mixed with history | Separate Apply for Membership page and link |
| Admin renewals identical to Members | Renewal-due queue/context rather than unfiltered clone |
| Representative individual-membership actions | Hide and enforce server-side restrictions |
| Official email had to differ from representative | Restriction removed |
| Association payments confused with finance | Renamed Application Payments |
| Modal close icons/visual sections | Visible close X and bordered sections; full-screen financial voucher entry |
| Very large card / wrapped actions | Compact digital cards and cleaner actions |
| Missing association identity | Logo/name branding across relevant records, communications and receipts |
| Manual submit-only finance search | Automatic/live filters and searchable growing dropdowns |
| Ledger master search did not filter | Source/controller/frontend search fix accepted |
| PL/pgSQL reversal `42702` | Forward `220000` migration fixes ambiguous alias/record reference |
| CSS build imported missing `/brand/section-accents.css` | Previous build-path correction accepted; preserve the actual fixed source |
| `--pretend` migration cursor error | Avoid cursor reads in pretend-sensitive paths; earlier failure must not be copied into new migrations |
| Strict installer rejected customized help JSON | Content-aware help merge with backups, idempotency and retained application-code hash checks |
| Help test demanded exactly 24 articles | Minimum baseline plus validation of every article; new guides may grow the catalogue |
| GST/TDS sidebar highlighted Finance overview | Explicit tax-route active matching; 12 selection checks |
| New tax-aware button disabled | Save required tax configuration first; no bypass of initialization/profile prerequisites |

## 16. Current artifacts to carry into the next chat

Use the latest actual project source, not a stack of old patches, as the implementation base. These files identify the development history:

| Artifact | Use |
|---|---|
| `FILMUNITY.zip` | Newer full source uploaded in the tax-correction chat; inspect contents/version before choosing baseline |
| `FILMUNITY-tax-correction-notes.patch` | Newer pending tax-aware correction diff; inspect/apply/test status first |
| `FILMUNITY_Multi_Line_Billing_and_Tax_Sidebar_Fix.zip` | Latest user-confirmed feature patch; v6 multi-line baseline |
| `FILMUNITY_Help_Article_Test_Fix.zip` | Test-only fixed-count correction |
| `FILMUNITY_GST_TDS_Installer_Help_Merge_Fix.zip` | Corrected installer preserving customized help content |
| `FILMUNITY_GST_TDS_Configuration_and_Tax_Billing.zip` | Original tax settings/billing stage; superseded installer behavior |
| `FILMUNITY_Tally_Credit_and_Debit_Notes.zip` | Ordinary accounting notes |
| `FILMUNITY_Searchable_Pickers_and_Master_Search.zip` | Searchable account choices and master filtering |
| `FILMUNITY_Finance_Reversal_Fix_and_Live_Search.zip` | Forward reversal fix and live finance filtering |
| `FILMUNITY_Tally_Stage_3_Invoices_and_Settlements.zip` | Bill registry/allocations/bridge |
| `FILMUNITY_Tally_Stage_2_Accounting_Foundation.zip` | Masters/FY counters foundation |
| `FILMUNITY_Tally_Stage_1_Gateway_and_Entry.zip` | Tally-style entry/gateway foundation |

A prior GCP first-deployment runbook also exists, but FILMUNITY production deployment was not confirmed in this conversation. It needs an update against the final source, queue/scheduler/storage design and real GCP choices before execution.

When sharing a fresh source archive, include application code, routes, migrations, resources, tests, manifests/lockfiles and project docs. Exclude `.env`, passwords, private keys, production database dumps, real member identity documents and unnecessary dependency/build folders. Include sanitized logs/migration status and the patch separately.

## 17. Remaining roadmap — order and completion gates

### Phase A — complete/review tax-aware corrections (immediate)

Resume the existing pending patch. Verify per-original-charge partial limits, tax residual paise, reviewed TDS retention/reversal, tenant/date locks, lifecycle/idempotency, allocations, reversals, reports/exports and UI evidence/audit. Produce actual installation/migration/test results before accepting it as complete.

### Phase B — professional and validated document issuance

Extend branded invoice/bill/note output with clear draft/posted/void history, line breakdowns, identity/source references and appropriate download/PDF options. Separately review statutory particulars, numbering, authorized signature and any e-invoice obligation. Do not label a generic HTML printout as complete tax compliance.

### Phase C — deeper vendor tax controls

Accountant-reviewed applicability/rate profiles with effective dates, cumulative threshold tracking, prior advance/credit/payment deductions, certificates and prevention of duplicate withholding. Add challan payment records/matching, outstanding tax reconciliation, exception workflows and appropriate returns/certificates/export outputs.

### Phase D — recurring billing, reminders and operations

Recurring invoice schedules/templates, duplicate-run protection, pause/cancel and fee snapshots; reminder policies, resend/history, retry/backoff and delivery state. Explicitly configure scheduler/queue commands and production monitoring; do not assume having an email class means messages will run unattended.

### Phase E — Tally Prime XML interoperability

XMLWriter-based, association-scoped master/voucher export with correct debit/credit sign convention and grouped/party ledgers. Validate actual import into the intended Tally version. Preserve external IDs, financial-year references, allocations, tax snapshots and clear limitations. Existing JSON/CSV export is not Tally XML.

### Phase F — existing-system XLS/XLSX/CSV migration

Implement a migration workspace: mapping/template download → dry-run validation → error workbook → approval → staged/idempotent import → reconciliation → controlled go-live.

Requirements: preserve legacy membership numbers and stable external IDs; deduplicate identities safely; do not send bulk notices accidentally; reconcile opening balances, unpaid bills, unapplied advances, tax liabilities and membership validity; retain original records/evidence and audit the import batch. Set the next member number above the imported sequence and confirm no collision before new issuance. A finance-only CSV/JSON export cannot by itself recreate the entire application.

### Phase G — release/deployment hardening

Review delegation/RBAC, audit retention and deletion policy for financial/member records; tenant security and document access; database backups/restore drills; queue/scheduler operations; authenticated email domains; tests/CI, privacy/security, license/docs and observability. Validate mobile UI after web workflows stabilize. Then implement the fresh deployment plan with explicit user authorization for external changes.

### Phase H — optional movie-title registration and broader community features

Some producers' associations need an opt-in movie-title registry. Each association owns an independent registry; associations are not automatically interconnected. Track local conflicts, eligibility, evidence, email availability enquiries/replies to other associations, configurable response deadline (intended default 30 days), manual approval, validity/renewal, transfer/dispute and audit trail. Registration is not statutory copyright. Talent discovery/community opportunity features remain broader product direction unless current source demonstrates delivery.

## 18. Operating instructions for the next assistant

1. Read this document and inspect the newest source/patch. Verify terminal/file access immediately; do not claim edits or tests when the runtime is unavailable.
2. Identify actual version/branch/migration state. Preserve local edits and use the newest source as authority over historical ZIPs.
3. Continue the existing tax-correction patch before inventing a replacement. Separate intended behavior, prepared code, applied migrations and user-verified results.
4. Keep the existing stack, schema, theme and workflows. Prefer existing services/components and forward migrations.
5. Make changes concrete and reviewable; do not repeatedly ask permission for authorized local/reversible work. Deployment, messaging and other external actions still need applicable authorization.
6. Keep precise validation notes: what ran, on which database/runtime, and what did not run. Do not substitute isolated SQL/wasm checks for Laravel/PostgreSQL integration tests.
7. Preserve financial history. No source overwrite shortcuts, database resets, posted-entry rewrites, floating-point money or automatic assumed tax exemptions.
8. Package Windows-friendly instructions with backup, preflight, migration, build, focused/full tests, smoke checks and recovery checkpoints. Content-merge help instead of replacing customized guides.
9. Update this checkpoint after successful user tests. The next verified count is unknown until then.

## 19. Ready-to-paste continuation prompt

> Continue my existing FILMUNITY project using Laravel 13, PostgreSQL, React, TypeScript, Inertia and Tailwind. Read the attached handoff, newest source and `FILMUNITY-tax-correction-notes.patch` first. My last confirmed checkpoint is multi-line GST/TDS billing, correct tax-sidebar selection, export schema v6, 164 Laravel tests/2016 assertions, 87 PostgreSQL checks, 9 PHP calculation checks, 17 help-merge checks and 12 sidebar checks passed. A newer tax-correction patch has been prepared but its application/migration/test status is not confirmed. First inspect whether it is already applied, review its original-charge limits, residual paise rounding, GST/TDS treatment, allocations, reversals, reports/exports and authorization, then guide or complete its safe integration. Preserve my current identity, association/member/payment/renewal workflows, olive-green UI, posted history and custom help content. Use forward migrations, exact money and the verified filmunity_test database; never reset my application database. Report actual checks and any remaining limits before moving to invoice/PDF output and deeper TDS/challan workflows.

---

**End of handoff.** This file intentionally distinguishes accepted working features from the newer unverified patch and future roadmap. Attach actual current source and results with it; do not rely on conversational memory alone.
