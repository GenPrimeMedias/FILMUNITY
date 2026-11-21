# Route and test organization cleanup — 21 November 2026

This maintenance change removes the duplicate dashboard declaration and repeated membership route include from `routes/web.php`. The surviving dashboard declaration retains `RedirectReviewerDashboard` inside the authenticated/verified group.

The 27 scenarios formerly contained in `MultiLineTaxTest` are divided as follows:

| Suite | Existing scenarios |
|---|---:|
| MultiLineTaxTest | 5 |
| TaxCorrectionWorkflowTest | 7 |
| TaxReportingAndDocumentsTest | 3 |
| TdsReconciliationTest | 2 |
| VendorTdsControlsTest | 4 |
| RecurringMemberInvoiceTest | 2 |
| LegacyMembershipPreviewTest | 4 |

`Tests\Support\FinanceWorkflowFixtures` holds the original setup/teardown and common fixture methods. Each suite retains `DatabaseTransactions`, and the existing `Tests\TestCase` database preflight is unchanged. The XLSX fixture remains within the legacy-preview suite. The original method source was verified before Pint; after formatting/import cleanup, resolved PHP syntax-tree hashes confirm unchanged scenarios, assertions, literal values and lifecycle/fixture behavior. The Help Centre workflow inventory references the relevant suites while retaining all unrelated current metadata and existing valid references.

There is no database migration, UI change, new business workflow, accounting modification or change to the expected total scenario count. The old `--filter=MultiLineTaxTest` now selects only its five billing scenarios; run all seven suites or the full suite to cover the former 27 scenarios. Historical handoff files describe their historical checkpoint and are not rewritten.

The package checks original source hashes, equivalent resolved method syntax after formatting, PHP composition and preserved transactional traits. The native Windows run passed all 202 tests with 5,881 assertions against `filmunity_test`; all 160 effective routes were unchanged. These checks do not certify live books. Use the corrected package README for native commands, installation preflight, before/after route comparison and reviewed rollback instructions.
