# FILMUNITY implementation checkpoint

**Checkpoint date — 19 November 2026, Asia/Kolkata**

**Current position — Individual profiles, association onboarding, association approval, workspace customization, and membership application review are delivered. The next phase is manual payment verification, receipts, membership activation, and limited member workspace access.**

## 1. How to use this checkpoint

This document records the work delivered during our implementation sessions and the behavior you have reported as working. The file, route, migration and test inventories were checked against the available delivered source files.

The complete Windows checkout at `D:\FILMUNITY\FILMUNITY` is not mounted in this workspace. Local changes, the installed database schema, the full route list, and the current results of the full test suite therefore require verification in that checkout. The delivered PHP feature tests were inspected but were not executed here. Recent frontend updates were checked with TSX syntax parsing, which is separate from TypeScript type checking and a production build.

Use the local verification commands in section 11 to attach the current migration status, route list and test results to this checkpoint. Treat the payment design in sections 13–17 as the proposed next implementation, with its assumptions stated explicitly.

## 2. Product workflow and current completion

| Stage | Intended behavior | Current position |
| --- | --- | --- |
| Individual registration | Create a FILMUNITY identity and verify email | Implemented, user confirmed |
| Professional profile | Complete and maintain a professional showcase | Implemented, user confirmed |
| Public profile | Publish the member showcase under a unique username | Implemented, user confirmed |
| Association sign-up | Choose association registration while creating an account | Implemented, user confirmed |
| Association application | Submit organisation details and registration certificate | Implemented, user confirmed |
| Platform review | FILMUNITY reviewer approves or declines the association | Implemented, user confirmed |
| Association activation | Approved registration becomes an owner-admin workspace | Implemented, user confirmed |
| Workspace configuration | Configure branding, membership plans and custom application fields | Implemented, user confirmed |
| Membership application | Individual chooses an approved association and membership type | Implemented, user confirmed |
| Association membership review | Association owner reviews only its own applications | Implemented, user confirmed |
| Payment request | Association requests the submitted membership fee | Next phase |
| Payment evidence | Member submits reference and payment proof | Next phase |
| Receipt and acknowledgement | Both sides access the payment record and issued receipt | Next phase |
| Membership activation | Association acknowledges payment and activates membership | Next phase |
| Member workspace | Active member enters that association with limited permissions | Next phase |
| Renewal and expiry | Manage Yearly membership renewal and expiration | Follow-on lifecycle work |

The application and review foundation is ready for the payment phase. Payment processing and active association-member permissions have not yet been delivered.

### 2.1 Technology and local environment

The project uses Laravel, PostgreSQL, React, TypeScript, Inertia.js and Tailwind CSS.
Your earlier local output reported Laravel 13.39.0, PHP 8.3.29 and PostgreSQL 18.1.
These are recorded environment observations. Capture current versions from the
local checkout when attaching verification evidence.

The development application has been accessed at `http://localhost:8003`.
Authentication and settings use the existing Laravel application setup.

### 2.2 Email configuration checkpoint

You resolved the earlier Gmail SMTP authentication error and confirmed email
delivery and verification were working. The application then delivered the
association approval confirmation to its submitted official contact address.

Relevant local configuration keys include:

- `APP_URL` — The application base URL used in generated links.
- `MAIL_MAILER` — SMTP for actual delivery.
- `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT` — SMTP transport settings.
- `MAIL_USERNAME`, `MAIL_PASSWORD` — Provider credentials kept in local configuration.
- `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` — FILMUNITY sender identity.
- `GOOGLE_REDIRECT_URI` and the existing Google client configuration.
- `FILMUNITY_ASSOCIATION_REVIEWER_IDS` — Platform reviewer account IDs.

Your supplied Gmail configuration used `smtp.gmail.com`, `smtps` and port
`465`. Keep the working credential values in your environment, outside this
checkpoint document. Clear configuration caches after changing environment values.

## 3. Identity, roles and dashboard routing

### 3.1 One identity with distinct access

The implementation uses the existing FILMUNITY user identity for member and administrator experiences.

| Identity or role | Current access |
| --- | --- |
| Ordinary verified member | Member dashboard, professional profile, My Memberships, own applications and uploaded documents |
| Association sign-up account before approval | Association application and own registration history |
| Association owner after approval | Own approved workspace, workspace setup, own membership review queue |
| FILMUNITY platform reviewer | FILMUNITY Admin, Registered accounts, association review queue, permitted application documents |
| Active paid association member | Access will be implemented in the next phase |

The existing association-admin implementation identifies the approved registration's applicant as its owner/admin. It does not yet provide a general staff-management system or multiple association-admin assignments.

### 3.2 Platform reviewer configuration

The configured reviewer IDs are read through `config/association-registration.php` and checked by `App\Support\FilmunityReviewer`.

For the reviewer account confirmed earlier in the session:

```dotenv
FILMUNITY_ASSOCIATION_REVIEWER_IDS=3
```

Use the actual user ID from your database. Multiple configured reviewer IDs can be comma-separated. Clear the Laravel configuration cache after changing this value.

### 3.3 Dashboard behavior

`RedirectReviewerDashboard` handles the normal dashboard entry:

1. The old `/dashboard?panel=applications` link redirects to `/membership-applications`.
2. An explicit member view or profile panel remains available through its dashboard query parameters.
3. Association onboarding intent can send an eligible sign-up account to its registration page.
4. Configured platform reviewers normally land on `/filmunity-admin`.
5. Approved association owners normally land on `/association-workspaces`.
6. Association sign-up accounts without an approved workspace land on association registration.
7. Ordinary members use their member dashboard.

### 3.4 Association sign-up eligibility

- `users.association_signup` persists the association sign-up choice.
- `RecordAssociationSignup` records the choice when the account-registration event occurs.
- The registration page and Google sign-in flow carry association onboarding intent.
- Ordinary accounts do not receive the Register an association action.
- Direct registration form access and submission are checked on the server.
- Existing accounts with association-registration applications were backfilled as association sign-up accounts by the migration.
- Existing ordinary accounts are not automatically converted into association sign-up accounts by clicking the old start URL.

## 4. Individual member profile and public showcase

The delivered profile wizard contains seven steps:

1. Industry & roles.
2. Basic info.
3. Photo & banner.
4. Work bio & links.
5. Portfolio.
6. Hero slider.
7. Review showcase.

The profile supports resumable drafts, username availability and reservation, a member overview, portfolio entries, photo/banner uploads, social links and a public showcase. The public profile uses a saved publication snapshot, so draft editing and public publication have distinct entry points.

The delivered public URL is:

```text
/community/@username
```

Publication validates the required profile content. The public showcase includes the profile sidebar, sections for professional information and selected work, social links, and the hero slider.

The dashboard and public-profile sidebars use professional icons. Wizard steps retain numbering.

## 5. Association registration and platform approval

### 5.1 Registration fields

| Group | Fields |
| --- | --- |
| Organisation | Legal organisation name, city, state or region, organisation type, film industry |
| Registration evidence | Registration number, registering authority, optional registration date, certificate upload |
| Official contacts | Official contact email, registered office address, optional website |
| Authorised representative | Role in the organisation |

Certificates accept one PDF, JPEG or PNG up to 5 MB. Files use the private local storage disk and a guarded download route.

### 5.2 Application rules

- An eligible account can have one pending or approved association application.
- A new application becomes available after the earlier application is declined.
- Declined records remain in registration history and the platform review queue.
- Submission checks eligibility again while locking the user's database row.
- A member's account email and an association's official email are distinct fields. The delivered validation does not impose a general rule requiring different values.

### 5.3 Platform decision

The reviewer can search, sort, filter and page association applications, expand one row, open the review dialog, and confirm approval or rejection.

A rejection requires a reason. The record stores the reviewer, decision note and review time. A completed decision cannot be submitted a second time through the same pending-review transition. The applicant cannot approve its own registration.

Approval opens the applicant's association workspace. Existing approved registrations can be used as workspaces without applying again.

The approval email is addressed to the submitted **official contact email**. Its sent timestamp is recorded. If mail transport fails, approval remains saved and the reviewer has an email retry action.

### 5.4 Current association statuses

| Stored status | Meaning |
| --- | --- |
| `pending` | Waiting for FILMUNITY review |
| `approved` | Registration approved and owner workspace available |
| `declined` | Registration declined, reason retained, reapplication allowed |

## 6. Association workspace configuration

### 6.1 Layout and branding

Workspace setup now stacks these full-width sections:

1. Membership types and fees.
2. Workspace branding.
3. Membership form fields.
4. Save action.
5. Membership form preview.

Branding includes display name, optional description, accent colour and optional logo. Logo formats are PNG, JPEG and WebP up to 1 MB, with a maximum dimension of 2048 × 2048.

The association accent is applied to branded form/preview sections. The wider FILMUNITY interface retains the cinematic dark green and yellow-green styling, with shared accent borders and existing theme support.

### 6.2 Mandatory membership plans

Every association configures both plans:

| Plan | Stored type | Configuration |
| --- | --- | --- |
| Yearly | `yearly` | Required fee |
| Life-Time | `lifetime` | Required fee |

Current implementation choices:

- Currency is INR.
- Fees allow ₹0 through ₹9,999,999.99.
- Settings store the fee in integer minor units, meaning paise.
- Applicants must choose one type.
- Submission reads the authoritative fee from association settings.
- The selected type, fee and currency are stored on the application.
- Later fee edits preserve the application's submitted amount.
- Existing association settings need both fees saved before accepting new applications.
- Existing applications show Not recorded for plan or fee fields introduced after they were submitted.

These plan settings and the applicant's plan choice are additional to the 25 custom fields.

### 6.3 Custom fields

Each association can configure up to **25** required or optional custom fields, apart from the default identity and membership choice fields.

| Editor label | Stored type |
| --- | --- |
| Short text | `text` |
| Long text | `textarea` |
| Number | `number` |
| Email | `email` |
| Phone | `tel` |
| Website URL | `url` |
| Date | `date` |
| Time | `time` |
| Dropdown | `select` |
| Single choice | `radio` |
| Multiple choices | `multiple` |
| Checkbox / acknowledgement | `checkbox` |
| Document upload | `file` |

Full name and email come from the verified FILMUNITY identity. A workspace with no custom fields uses the standard statement explaining the applicant's work and reason for joining.

Choice fields require at least two distinct options and support up to 20 options. Field labels, IDs and types are validated by the server.

### 6.4 Acknowledgement wording

Checkbox fields now support an acknowledgement description. A newly selected checkbox type starts with:

> I (member / association) agree with the Terms & Conditions and Privacy Policy.

The association can edit this wording. It appears beside the checkbox in both preview and live form. Required acknowledgements must be accepted. The submitted answer includes the original description so subsequent wording changes preserve what the applicant agreed to.

This is configurable plain text. Separate policy pages and policy-link management have not been introduced by this feature.

### 6.5 Document and version behavior

- Application documents accept PDF, JPEG or PNG up to 5 MB each.
- The total document size per membership application is limited to 10 MB.
- Files are stored on the private local disk.
- Applicant and owning association-admin downloads use a guarded route.
- Platform reviewers have their own guarded membership-document route through Registered accounts.
- Failed submissions clean up files stored during the failed attempt.
- Settings have a version number. Stale settings saves or submissions against a changed form are rejected with a reload message.
- Application snapshots preserve submitted field labels, answers and acknowledgement wording.

## 7. Membership applications and review

### 7.1 Individual application

The sidebar and member dashboard shortcut now say **My Memberships**. The underlying URL remains `/membership-applications`.

The page shows the available approved associations, the selected association's branding and form, required Yearly/Life-Time choice, and the member's own searchable application history.

A member can apply to multiple associations. The current implementation excludes the member's own association workspace from the available application choices.

There is one open application per member/association pair. A PostgreSQL partial unique index enforces this rule. Declined applications are retained and allow a fresh submission.

### 7.2 Association review

The association admin can review its own applications, inspect the original answers and documents, approve an application for payment, or decline it with a reason.

Cross-association ownership is checked in database queries. A separate association admin cannot review another association's application. Ordinary members cannot access the admin queue.

### 7.3 Membership application statuses

| Stored status | UI label | Meaning |
| --- | --- | --- |
| `pending` | Pending review | Association decision outstanding |
| `accepted_for_payment` | Approved | Review passed, awaiting a payment request |
| `declined` | Declined | Reason retained and reapplication possible |

**Approved currently means accepted for the next payment step. Active association membership is a later state that still needs implementation.**

## 8. Consistent record tables and navigation

The delivered uniform table components cover six primary screens:

| Screen | Main URL | Visibility |
| --- | --- | --- |
| Association Registration History | `/association-registration` | Eligible applicant's own records |
| Association review queue | `/association-registration/review` | Configured platform reviewer |
| Association workspace directory | `/association-workspaces` | Owner's approved workspaces |
| My Memberships | `/membership-applications` | Signed-in member's own applications |
| Membership applications | `/association-membership-review` | Owning association admin |
| Registered accounts | `/filmunity-admin/members` | Configured platform reviewer |

The shared presentation includes accent border, consistent header/row spacing, horizontal scrolling for narrower screens, search, sorting, pagination, and single-row expansion where applicable. Search and sorting operate on the server for the primary record lists.

Alphabetical letter-only grouping rows were removed. Names remain sortable.

Registered accounts supports verification filters and expands one visible account at a time. Inside that account, registration and membership histories have separate pagination and show submitted details, document links and decision notes.

RecordsTable and RecordsPagination provide the shared table presentation. Registration details are reused by registration history, platform association review and the workspace directory.

## 9. Current database inventory

### 9.1 Delivered migrations in chronological order

Migration names below are exact filenames delivered during this workflow. Laravel's original identity/authentication migrations also remain necessary and are outside this feature-specific list.

| Migration under `database/migrations/` | Purpose |
| --- | --- |
| `2026_11_17_000001_add_google_id_to_users_table.php` | Nullable unique Google identity |
| `2026_11_17_000002_create_professional_profile_drafts_table.php` | Profile JSONB, resumable wizard step, timestamps |
| `2026_11_17_000003_reserve_professional_profile_usernames.php` | Unique reserved username and eligible draft backfill |
| `2026_11_17_000004_add_public_snapshot_to_professional_profile_drafts.php` | Public publication snapshot |
| `2026_11_18_120000_create_association_registration_applications_table.php` | Association applications, reviewer decisions and indexes |
| `2026_11_18_161000_add_registration_evidence_to_association_applications.php` | Certificate path and registration evidence |
| `2026_11_18_171000_add_official_contacts_to_association_applications.php` | Official contacts, website, email timestamp and resubmission support |
| `2026_11_18_181000_create_association_membership_applications.php` | Membership applications and unique open-application index |
| `2026_11_18_191000_create_association_workspace_settings.php` | Branding/form settings and membership answer snapshot |
| `2026_11_18_201000_add_association_signup_to_users.php` | Persistent association sign-up eligibility and existing-applicant backfill |
| `2026_11_19_030000_add_membership_plans.php` | Yearly/Life-Time fees and submitted membership plan/fee |

The later uniform-table and acknowledgement-description updates need no additional migrations. Acknowledgement wording is stored in the existing settings JSON and submission snapshot JSON.

### 9.2 Core tables

| Table | Current responsibility |
| --- | --- |
| `users` | Shared identity, verification, Google ID, association sign-up eligibility |
| `professional_profile_drafts` | Private profile draft, wizard position, reserved username, published snapshot |
| `association_registration_applications` | Registration application, evidence, official contacts, decision and email state |
| `association_workspace_settings` | Branding, field schema, settings version and both membership fees |
| `association_membership_applications` | Member/association pair, status, submitted answers, notes, selected type and fee |

An approved association-registration row currently acts as the workspace identity. There is no separate paid-membership entitlement table yet.

### 9.3 Retention boundary

Declined records are preserved during ordinary rejection and reapplication. Some delivered foreign keys use cascading deletion from users or association registrations. This checkpoint does not certify permanent audit retention after hard deletion of those parent records.

The payment phase should define record retention for receipts and membership events before adding account or association deletion operations.

## 10. Files and routes

All paths in this section are relative to the Laravel project root.

### 10.1 Backend files

| File | Responsibility |
| --- | --- |
| `app/Models/User.php` | Identity model and Google-related identity support |
| `app/Http/Controllers/Auth/GoogleAuthenticationController.php` | Google redirect/callback and association sign-up intent |
| `app/Http/Controllers/ProfessionalProfileController.php` | Member dashboard, profile draft, avatar and username availability |
| `app/Http/Controllers/PublicProfessionalProfileController.php` | Public publication and showcase rendering |
| `app/Http/Controllers/AssociationRegistrationController.php` | Association onboarding, history, platform review, certificate and approval email |
| `app/Http/Controllers/AssociationWorkspaceController.php` | Owner workspace directory and overview |
| `app/Http/Controllers/AssociationWorkspaceSetupController.php` | Branding, fees, field configuration and logo response |
| `app/Http/Controllers/MembershipApplicationController.php` | Member submission/history, owner review and applicant/owner document access |
| `app/Http/Controllers/FilmunityAdminController.php` | Admin dashboard, account directory and reviewer document access |
| `app/Http/Middleware/RedirectReviewerDashboard.php` | Dashboard routing by role and onboarding intent |
| `app/Support/FilmunityReviewer.php` | Configured platform reviewer check |
| `app/Support/FilmunityNavigation.php` | Shared sidebar eligibility and role information |
| `app/Support/AssociationWorkspace.php` | Approved owner workspace query scope |
| `app/Support/AssociationMembershipForm.php` | Supported types, settings presentation, fee conversion/formatting |
| `app/Support/MembershipApplicationView.php` | Application presentation, fee formatting and guarded document links |
| `app/Listeners/RecordAssociationSignup.php` | Persist association sign-up choice on registration |
| `app/Mail/AssociationRegistrationApproved.php` | Official association approval notification |
| `config/association-registration.php` | Reviewer configuration |
| `config/professional-profile-roles.php` | Professional role catalogue integration |
| `app/Providers/AppServiceProvider.php` | Existing defaults and profile-avatar sharing integration |

The `HandleInertiaRequests.php` integration shares `FilmunityNavigation::forRequest($request)`. The dashboard route uses `RedirectReviewerDashboard`. Listener discovery registers `RecordAssociationSignup`, with explicit registration available where the application disables discovery.

### 10.2 Frontend files

| File | Responsibility |
| --- | --- |
| `resources/js/pages/auth/login.tsx` | Login screen |
| `resources/js/pages/auth/register.tsx` | Individual/association sign-up choice |
| `resources/js/components/google-sign-in.tsx` | Transparent Google button |
| `resources/js/pages/dashboard.tsx` | Member dashboard and profile entry points |
| `resources/js/components/member-dashboard-summary.tsx` | Profile summary and My Memberships shortcut |
| `resources/js/components/professional-profile.tsx` | Resumable profile wizard |
| `resources/js/components/professional-profile-overview.tsx` | Profile overview |
| `resources/js/components/professional-profile.module.css` | Profile/wizard styling |
| `resources/js/components/profile-hero-preview.tsx` | Hero media preview |
| `resources/js/components/app-sidebar.tsx` | Role-aware navigation |
| `resources/js/components/nav-main.tsx` | Active route state and navigation styling |
| `resources/js/components/records-table.tsx` | Shared table and pagination presentation |
| `resources/js/components/association-registration-details.tsx` | Reusable registration details and certificate link |
| `resources/js/components/association-membership-fields.tsx` | Shared live/preview custom-field renderer |
| `resources/js/components/membership-application-list.tsx` | Shared membership table, details and decisions |
| `resources/js/pages/association-registration.tsx` | Association form, applicant history and platform review |
| `resources/js/pages/association-workspaces.tsx` | Workspace directory and overview |
| `resources/js/pages/association-workspace-setup.tsx` | Branding, both plans, field editor and preview |
| `resources/js/pages/membership-applications.tsx` | My Memberships and member application |
| `resources/js/pages/association-membership-review.tsx` | Association membership queue |
| `resources/js/pages/filmunity-admin.tsx` | Platform dashboard summary |
| `resources/js/pages/filmunity-admin-members.tsx` | Registered accounts and expanded histories |
| `resources/js/lib/professional-role-catalog.json` | Frontend professional role catalogue |

### 10.3 Public presentation and styles

- `resources/views/community-profile.blade.php` — Public showcase.
- `public/brand/community-profile.css` — Public profile styling.
- `public/brand/community-profile.js` — Public showcase interaction/slider.
- `public/brand/section-accents.css` — Shared section/card accent borders.
- `public/brand/social/` — Social-platform icon assets and related licence file.
- `resources/css/app.css` — Theme and shared CSS imports.

The shared accent CSS import was delivered as an integration instruction for both the app stylesheet and standalone public-profile stylesheet. Confirm these imports remain present in the local checkout.

### 10.4 Route files

- `routes/web.php` retains existing authentication/profile routing and includes the feature route files.
- `routes/association-registration.php` contains association onboarding, platform admin and workspace entry routes.
- `routes/membership-applications.php` contains membership application, workspace setup, logo and platform membership-document routes.
- `routes/settings.php` contains the existing account settings routes.

### 10.5 Main delivered routes

| Method | URI | Route name / handler |
| --- | --- | --- |
| GET | `/` | `home` |
| GET | `/auth/google/redirect` | `auth.google.redirect` |
| GET | `/auth/google/callback` | `auth.google.callback` |
| GET | `/dashboard` | `dashboard` |
| GET | `/professional-profile/avatar` | `professional-profile.avatar` |
| GET | `/professional-profile/username-availability` | `professional-profile.username-availability` |
| PUT | `/professional-profile/draft` | `professional-profile.draft` |
| POST | `/professional-profile/publication` | `professional-profile.publish` |
| DELETE | `/professional-profile/publication` | `professional-profile.unpublish` |
| GET | `/community/@{username}` | `community.profile` |
| GET | `/association-registration/start` | `association-registration.start` |
| GET | `/association-registration` | `association-registration.index` |
| POST | `/association-registration` | `association-registration.store` |
| GET | `/association-registration/review` | `association-registration.review` |
| GET | `/association-registration/{application}/certificate` | `association-registration.certificate` |
| PATCH | `/association-registration/{application}/review` | `association-registration.decision` |
| POST | `/association-registration/{application}/approval-email` | `association-registration.approval-email` |
| GET | `/filmunity-admin/login` | `filmunity-admin.login` |
| GET | `/filmunity-admin` | `filmunity-admin.dashboard` |
| GET | `/filmunity-admin/members` | `filmunity-admin.members` |
| GET | `/association-workspaces` | `association-workspaces.index` |
| GET | `/association-workspaces/{workspace}` | `association-workspaces.show` |
| GET | `/association-workspaces/{workspace}/setup` | `association-workspace-setup.edit` |
| POST | `/association-workspaces/{workspace}/setup` | `association-workspace-setup.update` |
| GET | `/membership-applications` | `membership-applications.index` |
| POST | `/membership-applications` | `membership-applications.store` |
| GET | `/membership-applications/{application}/documents/{field}` | `membership-applications.document` |
| GET | `/membership-applications/{association}/logo` | `membership-applications.logo` |
| GET | `/association-membership-review` | `association-membership-review.index` |
| PATCH | `/association-membership-review/{application}` | `association-membership-review.decide` |
| GET | `/filmunity-admin/membership-applications/{application}/documents/{field}` | `filmunity-admin.membership-document` |

Standard registration, login, logout, password reset, email verification, two-factor and settings routes are supplied by the existing application/authentication setup. Their full installed list must come from `php artisan route:list`.

Useful query parameters include `q`, `sort`, `direction`, `status`, `page`, association queue scope `association`, and account-directory `verification` / `account`. Expanded account histories use `registrations_page` and `applications_page` independently.

## 11. Tests, verification evidence and local checkpoint commands

### 11.1 Test files and intended coverage

| Test file | Delivered coverage |
| --- | --- |
| `tests/Unit/TestDatabaseConfigurationTest.php` | Verify PostgreSQL test endpoint, database and role |
| `tests/Feature/Auth/GoogleAuthenticationTest.php` | Google authentication behavior |
| `tests/Feature/ProfessionalProfileTest.php` | Profile draft, username and dashboard behavior |
| `tests/Feature/PublicProfessionalProfileTest.php` | Publication and public profile behavior |
| `tests/Feature/AssociationRegistrationTest.php` | Private evidence, reviewer authorization, decisions, official-email recipient, retained declines, own-history search/pagination |
| `tests/Feature/AssociationWorkspaceTest.php` | Sign-up intent, persisted eligibility, approval/workspace access and directory owner scope |
| `tests/Feature/MembershipApplicationTest.php` | Multiple associations, duplicate prevention, scoped review, form validation/snapshots, acknowledgements, 25 fields, private files, plan requirement and fee preservation |
| `tests/Feature/FilmunityAdminTest.php` | Admin entry and routing, protected directory, search/sort/pagination, expanded histories and reviewer-only documents |

The existing auth, settings, dashboard and API health tests also remain part of the project.

The test database guard uses `DatabaseTransactions` for database-backed tests and checks the real PostgreSQL connection. Feature tests require their schema already installed in `filmunity_test`. Run the guard before the feature suite.

### 11.2 Evidence already supplied

You supplied successful local TypeScript/build output and an earlier successful test database preflight. You also supplied a passing `AssociationRegistrationTest` run with 68 assertions before the later feature increments.

Subsequent flows were confirmed working through your application checks. These confirmations are recorded here without treating them as proof that every later test suite has run.

Recent delivered TSX changes passed syntax parsing here. The current full TypeScript check, Vite build, PHP suite and migration status must be captured from your Windows checkout.

### 11.3 Local read-only inventory and build verification

Run from the project root. Copy only the commands inside the code blocks.

```powershell
Set-Location -LiteralPath 'D:\FILMUNITY\FILMUNITY'
git status --short
php artisan migrate:status
php artisan route:list
php artisan event:list
npm run types:check
if ($LASTEXITCODE -ne 0) { throw 'TypeScript check failed.' }
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Vite build failed.' }
```

The build can regenerate frontend assets and route helper output. Migration status lists installed migrations without applying any.

### 11.4 Test database preflight and feature suite

These values match the development test endpoint you supplied earlier. Use the values currently approved for your local test environment if they have changed.

```powershell
$env:FILMUNITY_TEST_DB_HOST = '127.0.0.1'
$env:FILMUNITY_TEST_DB_PORT = '5432'
$env:FILMUNITY_TEST_DB_USER = 'filmunity'
.\vendor\bin\phpunit.bat --filter=TestDatabaseConfigurationTest
if ($LASTEXITCODE -ne 0) { throw 'Test database preflight failed.' }
php artisan test --filter='AssociationRegistrationTest|AssociationWorkspaceTest|MembershipApplicationTest|FilmunityAdminTest|ProfessionalProfileTest|PublicProfessionalProfileTest'
if ($LASTEXITCODE -ne 0) { throw 'Feature test verification failed.' }
```

If the preflight passes but a feature test reports missing tables or columns, apply the missing migrations to the verified test database through your established test migration procedure before rerunning it.

### 11.5 Checkpoint acceptance list

- [ ] Local route list matches the delivered feature routes.
- [ ] All eleven feature migrations are installed in the development database.
- [ ] The verified test database has the same required feature schema.
- [ ] TypeScript check and production build pass.
- [ ] Feature suite passes and its output is retained.
- [ ] Ordinary member, association applicant, association owner and platform reviewer experiences were checked separately.
- [ ] Existing approved/declined registrations remain visible after the UI updates.
- [ ] Both membership fees are saved for each association that should accept applications.
- [ ] A submitted acknowledgement displays its original wording after settings are edited.
- [ ] Registration approval email reaches the official contact address.
- [ ] Application documents can be downloaded only through their authorized routes.
- [ ] Database backup, private-upload backup and a reviewed source commit identify this checkpoint.

## 12. Latest delivery order and source precedence

The implementation was delivered as incremental updates. Later versions of a shared file supersede earlier delivered versions, with local edits merged deliberately.

Recent feature packages in order:

1. FILMUNITY_Membership_Application_Flow.zip
2. FILMUNITY_Workspace_Setup_and_Membership_Forms.zip
3. FILMUNITY_Association_Signup_and_Extended_Fields.zip
4. FILMUNITY_Membership_Review_Queue.zip
5. FILMUNITY_Remove_Queue_Letter_Headers.zip
6. FILMUNITY_Membership_Plans_and_Registered_Accounts.zip
7. FILMUNITY_Uniform_Record_Tables.zip
8. FILMUNITY_Workspace_Layout_and_Acknowledgement.zip

For the latest overlapping files:

| Latest update | Important files it supplies |
| --- | --- |
| Workspace Layout and Acknowledgement | Workspace setup page, custom-field renderer, setup controller, membership controller, membership table/details and MembershipApplicationTest |
| Uniform Record Tables | Registration controller/page/details, workspace controller/page, shared RecordsTable/RecordsPagination, registered-account page, registration/workspace tests |
| Membership Plans and Registered Accounts | Plan migration, fee/form support, MembershipApplicationView, platform admin controller, membership route file, sidebar/navigation, My Memberships page/shortcut and FilmunityAdminTest |
| Association Signup and Extended Fields | Sign-up eligibility migration/listener, dashboard routing, shared navigation eligibility, Google flow and field type expansion |

Keep `records-table.tsx` when applying the acknowledgement package because its membership table imports the shared components.

## 13. Next phase — payment, receipt and activation

### 13.1 Target outcome

An approved membership applicant should be able to pay the correct submitted fee, provide evidence, receive an association receipt, and obtain limited membership access after association acknowledgement.

The association must be able to track what it requested, what the applicant submitted, who verified it, which receipt was issued and when membership became active.

### 13.2 First delivery approach

The planned first payment implementation uses manual/offline payment references and uploaded evidence. An association supplies its collection instructions, such as bank transfer or UPI details. The member completes the transfer and submits its reference/proof.

The initial payment design should use the existing submitted plan and fee as the amount source. It should retain separate review, payment and membership state.

An online gateway can be integrated after the association collection/settlement model and provider are chosen. That integration is a later extension of the payment records and verification flow.

### 13.3 Complete user journey

1. Association admin approves a pending application.
2. The approved record displays Request payment.
3. Admin checks the captured plan and amount, adds payment instructions and an optional due date, and confirms the request.
4. My Memberships shows the request, amount, reference, instructions and due date.
5. Member pays through the stated channel.
6. Member submits payment reference, payment date and proof.
7. Both sides can view the submitted payment evidence and its pending-verification state.
8. Association admin checks the transaction and either returns it for correction with a reason or acknowledges payment.
9. Successful acknowledgement creates the receipt record and membership entitlement together.
10. Both sides can open/download the issued receipt.
11. My Memberships shows Active, membership number, plan and validity.
12. The member can enter that association's workspace with its Member permissions.
13. Email/status notifications follow the committed outcome.

For a zero-fee membership, provide a specific zero-fee confirmation path and membership confirmation record. Avoid asking the applicant to upload evidence of a transfer for a zero amount.

## 14. Proposed payment and membership state design

These states are a design proposal for the next implementation. The current database does not yet contain them.

| Area | Proposed states | Purpose |
| --- | --- | --- |
| Application review | Existing pending / accepted_for_payment / declined | Keep the association's review decision |
| Payment request | Requested / proof submitted / acknowledged / cancelled | Track fee collection |
| Payment evidence attempt | Submitted / accepted / returned | Preserve each review attempt |
| Membership | Active / expired / suspended | Control workspace entitlement |

Returning payment evidence should preserve the submission, review reason and files. It should allow a new attempt against the applicable payment request.

A request cancelled before payment should retain its audit record. Changes after a member has submitted evidence need an explicit reviewed transition, rather than silently replacing the requested amount.

### 14.1 Proposed validity rules for the first activation

The following defaults still need to be adopted during payment implementation:

- Membership starts when the association acknowledges payment and activates it.
- Yearly membership ends at the same local date/time one calendar year later, with a defined leap-day rule.
- Life-Time membership has no scheduled expiry date.
- Yearly access checks the stored end time on every protected request.
- Expiry display and reminders can use a scheduled job, while access control reads the actual validity record.
- Renewal should create another payment/term record and preserve earlier receipts.

Use a consistent timezone and an explicit expiry boundary. Store timestamps consistently and format them for the association/member display.

### 14.2 Existing applications without a plan

Older applications may have null membership type and fee. Before requesting payment for those records, add an explicit applicant plan-selection/confirmation step. Record when the type and amount were confirmed.

Do not infer a historical fee from a new tariff without a visible confirmation. The existing application history must remain readable during this transition.

## 15. Proposed data, files, routes and UI for the payment phase

All names in this section are suggested implementation names. They are not installed files, migrations or routes.

### 15.1 Proposed database records

| Proposed table | Main information |
| --- | --- |
| `association_payment_requests` | UUID, application/member/association IDs, plan, amount in minor units, currency, instructions snapshot, request reference, due date, state, creator and timestamps |
| `association_payment_submissions` | Request ID, reference, payment date, evidence path, submission time, review state, reviewer, review note and acknowledgement time |
| `association_payment_receipts` | Receipt number, association, member, request/submission, confirmed amount, issue time, issuer and frozen receipt content |
| `association_memberships` | Member/association IDs, membership number, plan, status, start/end, activation source and receipt relation |
| `association_membership_events` | Actor, action, previous/new state, reason and timestamp for lifecycle events |

Use existing workspace settings for approved payment collection instructions where that fits the codebase. Preserve the instructions and branding used for a particular request/receipt as snapshots.

The final migrations should define uniqueness for request transitions, membership numbers and receipt numbers within the association. Verification of one submission should be idempotent and should not generate a second receipt or entitlement on retry.

### 15.2 Proposed backend responsibilities

- A payment controller handles scoped request creation, member evidence submission and association verification.
- A membership activation service commits acknowledged payment, receipt metadata and membership activation consistently.
- A receipt renderer produces a printable/downloadable association-branded receipt from the stored receipt record.
- Access policies or scoped queries distinguish association Admin and Member.
- Notifications run after the transaction is committed. A mail or rendering failure must be retryable without duplicate payment acknowledgement.
- A membership presenter exposes plan, fee, payment status, receipt and validity to My Memberships.

Likely files include:

- `app/Http/Controllers/AssociationPaymentController.php`
- `app/Http/Controllers/AssociationMembershipController.php`
- `app/Support/AssociationMembershipActivation.php`
- `app/Policies/AssociationMembershipPolicy.php`
- `app/Mail/MembershipPaymentRequested.php`
- `app/Mail/MembershipActivated.php`
- `resources/views/receipts/association-membership.blade.php`

Existing code should be reused where it provides the same validated behavior. Exact file/class choices should follow the local checkout's conventions.

### 15.3 Proposed route responsibilities

| Suggested action | Suggested route |
| --- | --- |
| Owner sends payment request | POST `/association-membership-review/{application}/payment-request` |
| Member opens payment request | GET `/membership-payments/{payment}` |
| Member submits evidence | POST `/membership-payments/{payment}/submissions` |
| Owner acknowledges or returns evidence | PATCH `/association-payments/{payment}/submissions/{submission}` |
| Authorized user downloads proof | GET `/membership-payments/{payment}/submissions/{submission}/proof` |
| Authorized user opens/downloads receipt | GET `/membership-receipts/{receipt}` |
| Active member enters its workspace | GET `/association-memberships/{membership}/workspace` |

Use the existing `auth` / `verified` middleware plus resource ownership and role checks. Final route names can change when implementation is integrated.

### 15.4 Planned navigation and presentation

| User | Planned changes |
| --- | --- |
| Member | My Memberships displays payment requests, upload action, verification status, receipt and Active membership |
| Association admin | Separate Payments link with searchable/sortable queue and expandable evidence details |
| Association admin | Members link for active/expired membership directory |
| Platform admin | Account expansion can display payment/membership audit information under the existing reviewer scope |
| Active association member | Workspace switch/entry with limited member navigation |

New queues should reuse RecordsTable and RecordsPagination, use one expanded row, preserve filters during paging, and use confirmation dialogs for acknowledgement/return actions.

## 16. Payment permissions and receipt requirements

### 16.1 Proposed access matrix

| Action | Applicant | Owning association admin | Another association admin | Platform reviewer |
| --- | --- | --- | --- | --- |
| Create payment request | No | Yes | No | Audit view |
| Submit proof | Own request | No | No | Audit view |
| View proof | Own submission | Yes | No | Authorized audit view |
| Acknowledge/return proof | No | Yes | No | Audit view |
| Download receipt | Own receipt | Yes | No | Authorized audit view |
| Use member workspace | Own active membership | Admin workspace | No | Existing platform tools |

The Member role must not inherit association-wide configuration, application decisions, tariff management or payment verification permissions.

### 16.2 Receipt content

The planned association receipt should contain:

- Association display/legal name, logo, approved branding and contact information.
- Unique association-scoped receipt number and issue date.
- Member name and FILMUNITY identity reference.
- Membership number and Yearly/Life-Time type.
- Amount and INR currency.
- Payment reference/date and acknowledgement date.
- Application/request references.
- Membership start/end date where applicable.
- Association acknowledging officer identity.

The platform confirmation email recipient for membership transactions should be the member's verified account email. The association can access its own receipt through its workspace. Sending copies to the official contact email can be added as a visible association notification setting.

Tax-specific invoice fields and numbering rules require an explicit association requirement before they are added.

## 17. Payment-phase implementation order and acceptance

### 17.1 Delivery sequence

1. Add payment and membership data records with ownership and uniqueness constraints.
2. Add collection instructions to workspace settings and owner payment-request action.
3. Add member payment request display and evidence submission.
4. Add the association Payments queue and return/acknowledgement confirmation.
5. Add receipt creation and idempotent membership activation.
6. Add member-only association workspace access and validity checks.
7. Add email notifications, retries and status feedback.
8. Verify the full path for Yearly, Life-Time, zero-fee and older applications.

### 17.2 Required test coverage

- Only approved applications with confirmed plan/fee can receive payment requests.
- Fees come from the authoritative recorded amount.
- Applicant, association admin and platform audit roles are scoped correctly.
- Duplicate requests, repeated acknowledgements and concurrent verification do not duplicate receipts or membership.
- Required references and file type/size validation work.
- Returned evidence is retained and can be replaced through a new attempt.
- Receipt and activation either commit consistently or can be retried from a recoverable state.
- Receipt and proof downloads reject unrelated users.
- Yearly dates and leap-day behavior follow the chosen rule.
- Life-Time records have no scheduled end.
- Expired or suspended records lose member workspace access.
- Member access cannot perform admin actions.
- Existing approved applications with missing plan/fee use the confirmation path.
- Notification failure preserves committed records and permits retry.

### 17.3 End-to-end acceptance scenario

Use a member, Association A admin, Association B admin and a platform reviewer.

1. Member submits a Yearly application to A.
2. A approves it and sends the captured fee request.
3. Member submits payment reference/proof.
4. A returns it with a reason, then accepts a corrected submission.
5. One receipt and one membership are created.
6. Member and A open the same issued receipt.
7. Member enters A with member-only permissions.
8. B cannot view or decide A's payment.
9. Repeat with Life-Time and zero-fee applications.
10. Confirm all original application answers, acknowledgement text, decisions and payment attempts remain traceable.

## 18. Later roadmap after the payment phase

- Yearly renewal queue, expiry reminders and renewal term history.
- Association staff/admin invitations and permission management.
- More member workspace features and association-specific services.
- Optional association modules, including the independently managed movie-title registration module previously discussed.
- Online payment gateway integration once collection and settlement requirements are chosen.
- Full-repository UI/access review when the complete checkout is available.
- Deployment and operational readiness for the central hosted offering, followed by self-hosted packaging as planned.

## 19. Resume brief for the next implementation session

> Continue FILMUNITY from the 19 November 2026 implementation checkpoint. Individual profiles, association sign-up/approval, owner workspaces, branding, 25 custom membership fields, acknowledgement descriptions, mandatory Yearly/Life-Time fees, My Memberships, scoped membership review and uniform record tables are implemented. Current membership approval is stored as accepted_for_payment and has not activated a paid member role. Implement manual payment requests, proof/reference submission, association acknowledgement, receipts and membership activation with limited association-member workspace access. Preserve the existing theme, RecordsTable/RecordsPagination, private document access, application snapshots and audit history. Handle existing applications without plan/fee, Yearly validity, Life-Time validity and zero-fee confirmation explicitly. Verify changes against the local checkout and the guarded filmunity_test database.

---

Checkpoint prepared from the delivered implementation files and the user's reported working flows. Attach current local verification output and the source commit reference when recording the repository checkpoint.
