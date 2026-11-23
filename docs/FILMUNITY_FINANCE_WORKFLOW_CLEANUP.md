# FILMUNITY finance workflow cleanup — installation, troubleshooting and tests

Prepared 21 November 2026 (Asia/Calcutta). No migration is required.

## Scope and package contents

- `routes/web.php`: one `/dashboard` declaration, still named `dashboard`, using `ProfessionalProfileController@dashboard`, `auth`, `verified` and `RedirectReviewerDashboard`; one inclusion of `membership-applications.php`.
- `resources/help/association-admin.json`: existing Tally article IDs retained. Accounts reported a successful TallyPrime 7.2 import with no errors. Per-ledger reconciliation and repeat-import behavior remain pending unless separately evidenced. Finance overview wording records the same distinction.
- `resources/help/workflow-inventory.json`: matching evidence wording; article IDs/paths unchanged.
- `tests/Feature/AssociationHelpTest.php`: assertions distinguish the reported successful import from outstanding reconciliation/duplicate-import checks.
- `tests/Feature/FinanceWorkflowCleanupTest.php`: source/route registration, middleware, reviewer redirect, ordinary dashboard/applications access and guest/unverified guards.
- `docs/FILMUNITY_FINANCE_WORKFLOW_BLUEPRINT.md`: consolidated reviewed workflow and Mermaid chart. It is not a verbatim recovered chat artifact; no separate original blueprint/chart was available in the visible conversation.
- This instruction document.

The ZIP contains an allowlisted `files/` directory with these seven project files, `FILMUNITY-finance-workflow-cleanup.patch`, `manifest.json` with before/after SHA-256 hashes, and a root `README.md` copy of these instructions. It excludes environment files, logs, databases, dependencies, member data and unrelated files.

The patch is a **session delta against the checkout before this cleanup**, not a diff against repository HEAD. Full-file copies preserve their existing source content for reference; use the patch to avoid replacing unrelated local edits. No previous milestone patches or migrations are bundled.

## Installation into another matching checkout

The current working checkout already contains this cleanup. Do not apply it a second time.

1. Extract the ZIP to a separate folder. Read `manifest.json` and review the patch. Preserve your target's modified/untracked files using your usual backup process. Do not reset, clean, migrate or deploy.
2. In the matching FILMUNITY project root, inspect `Get-Location` and `git status --short`. This package assumes the existing Help Centre, finance workflows, guarded `filmunity_test` setup and installed project dependencies.
3. Check first, using the actual extracted patch path:

   ```powershell
   git apply --check --ignore-whitespace 'C:\path\to\extracted\FILMUNITY-finance-workflow-cleanup.patch'
   ```

4. If the check passes and the patch is appropriate for that target, apply exactly once:

   ```powershell
   git apply --ignore-whitespace 'C:\path\to\extracted\FILMUNITY-finance-workflow-cleanup.patch'
   ```

   The whitespace option accommodates Windows line endings in context; intended edits still come from the patch. Do not use `--reject` or blindly overwrite the `files/` copies on a mismatched checkout. Merge only the documented changes if context differs, preserving target edits.
5. Run the checks below. A JSON/content-only help update and route cleanup require no frontend rebuild, dependency installation, schema change or scheduler enablement. Restart your own local app process only if needed for its normal route/content reload behavior. No production cache operation is part of this package.

## Verification commands

Run from the project root in PowerShell with existing dependencies. Database tests must use only the isolated, already-provisioned `filmunity_test` database. Do not run migrations or reset commands to make tests pass.

```powershell
php -l routes/web.php
php artisan route:list --path=dashboard
php artisan route:list --name=membership-applications
vendor/bin/pint --test routes/web.php tests/Feature/AssociationHelpTest.php tests/Feature/FinanceWorkflowCleanupTest.php
vendor/bin/phpstan analyse --no-progress --memory-limit=1G routes/web.php
node_modules/.bin/vp.cmd fmt --check resources/help/association-admin.json resources/help/workflow-inventory.json
node --experimental-strip-types --test tests/Frontend/help-search.test.mjs

$env:FILMUNITY_TEST_DB_HOST='127.0.0.1'
$env:FILMUNITY_TEST_DB_PORT='5432'
$env:FILMUNITY_TEST_DB_USER='filmunity'
php artisan test --compact --filter='FinanceWorkflowCleanupTest|AssociationHelpTest|DashboardTest|MembershipApplicationTest|FilmunityAdminTest|RepresentativeManagementTest'
```

The existing `Tests\TestCase` verifies `APP_ENV=testing`, PostgreSQL configuration, the actual `filmunity_test` connection/user and permitted transaction strategy. Leave those guards intact. Use your existing local credential configuration; no credentials are included here. Tests use synthetic users, fake mail and rolled-back transactions.

## Results recorded for this package

- Focused PHP suite: **26 tests passed, 3,561 assertions** in `filmunity_test`.
- Route registration: **one dashboard route**, with the same name/controller and middleware; membership application index/store/apply/confirm-plan/document/retry-notices/logo routes register.
- PHP syntax, targeted Pint, PHPStan and JSON formatting checks are recorded after the final source edit.
- Five existing Help Centre search tests cover field search, Tamil, leading zeros, section scoping and exclusion of related-navigation labels from content matches.
- No frontend application code changed in this cleanup. Browser interaction, PDF output and native Tally execution were not rerun; no new claim of per-ledger reconciliation or repeat-import safety is made.

## Browser and Accounts follow-up

- As a configured reviewer, `/dashboard` redirects to FILMUNITY Admin; `/dashboard?view=member` intentionally permits the member dashboard. A verified ordinary member can open `/dashboard` and `/membership-applications`; `/dashboard?panel=applications` redirects to the application list.
- Open `/association-help/finance?topic=start` and `?topic=tally-export`; both must attribute the no-error 7.2 import to Accounts and leave ledger reconciliation/repeat import pending. Download the finance guide and check the same wording. Check search and **Print / Save as PDF** in the browser if output is needed.
- Render the Mermaid fence in a Mermaid-capable Markdown viewer for visual layout review. The chart source is provided; no external renderer or published diagram was used in this cleanup.
- Obtain the Accounts import log/result, then independently record voucher counts, debit/credit totals and movement for each target ledger against the exact exported batch. Check repeat-import behavior in a disposable separate TallyPrime test company. Do not import into live books from these instructions.
- The Tally export screen's older pending-validation notice is unchanged because the requested edit targets Help Centre content and inventory. Do not interpret that older UI text as overriding the attributed Accounts report, or the report as evidence of the still-pending checks.

## Troubleshooting

- **Patch does not apply:** stop and inspect context/before hashes. It may already be applied or the target may differ. Use `git apply -R --check --ignore-whitespace` with the patch path only as a read-only already-applied diagnostic. Do not reset the checkout or force full-file replacement.
- **Dashboard still redirects unexpectedly:** inspect reviewer IDs and whether the user is an approved association owner/representative. Existing middleware intentionally routes owners to their workspace and representatives to registration. Query overrides are explicitly tested. Also check whether a local process is serving cached routes from an older checkout; do not change permissions to bypass routing.
- **Verification redirect:** an unverified user must verify email; the dashboard still uses `auth` and `verified`.
- **Membership route missing:** confirm one `require __DIR__.'/membership-applications.php';` remains and inspect the existing route file. No duplicate inclusion is needed.
- **Help still says import unverified:** verify the current JSON being served, finance section summary and inventory. The Tally screen notice is a separate existing message outside this change. No article rename or database migration is required.
- **Database test guard fails:** check the explicit test endpoint, role and existing test configuration. Never point the test run at live books, disable the guard or reset a database.
- **Vite/font-manifest test error:** do not run an asset build concurrently with HTTP feature tests. Let any build finish, then rerun the focused tests. The database does not need resetting.
- **Need to undo only this cleanup:** first review a reverse dry run against your current files. If later edits overlap, merge manually from the patch; do not use `git reset` or remove unrelated untracked files.
