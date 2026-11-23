<?php

use App\Http\Controllers\AccountingMastersController;
use App\Http\Controllers\AssociationFinanceController as Finance;
use App\Http\Controllers\AssociationTaxController;
use App\Http\Controllers\BillingInvoiceReminderController;
use App\Http\Controllers\BillSettlementController;
use App\Http\Controllers\CorrectionNoteController;
use App\Http\Controllers\FinanceDocumentController;
use App\Http\Controllers\RecurringMemberInvoiceController;
use App\Http\Controllers\TallyXmlExportController;
use App\Http\Controllers\TdsReconciliationController;
use App\Http\Controllers\VendorTdsHistoryController;
use App\Http\Controllers\VendorTdsReviewController;
use App\Http\Controllers\VendorTdsThresholdController;
use App\Http\Middleware\ProtectWorkspaceMutation;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/association-finance', [Finance::class, 'home'])->name('association-finance.home');
    Route::prefix('/association-finance/{association}')->whereUuid('association')->middleware(ProtectWorkspaceMutation::class)->group(function (): void {
        Route::get('/tax', [AssociationTaxController::class, 'index']);
        Route::get('/tax/vendor-threshold', [VendorTdsThresholdController::class, 'show']);
        Route::get('/tax/vendor-history', [VendorTdsHistoryController::class, 'show']);
        Route::get('/tax/vendor-reviews', [VendorTdsReviewController::class, 'index']);
        Route::post('/tax/vendor-reviews', [VendorTdsReviewController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/tax/vendor-reviews/{review}/evidence', [VendorTdsReviewController::class, 'evidence'])->whereUuid('review');
        Route::get('/tax/challans', [TdsReconciliationController::class, 'index']);
        Route::post('/tax/challans', [TdsReconciliationController::class, 'record'])->middleware('throttle:20,1');
        Route::post('/tax/challans/matches', [TdsReconciliationController::class, 'match'])->middleware('throttle:20,1');
        Route::post('/tax/challans/matches/{match}/release', [TdsReconciliationController::class, 'release'])->whereUuid('match')->middleware('throttle:20,1');
        Route::post('/tax/settings', [AssociationTaxController::class, 'settings'])->middleware('throttle:20,1');
        Route::post('/tax/documents', [AssociationTaxController::class, 'save'])->middleware('throttle:20,1');
        Route::get('/tax/documents/{voucher}', [AssociationTaxController::class, 'show'])->whereUuid('voucher');
        Route::post('/billing/notes', [CorrectionNoteController::class, 'save'])->middleware('throttle:20,1');
        Route::post('/billing/notes/{voucher}', [CorrectionNoteController::class, 'save'])->whereUuid('voucher')->middleware('throttle:20,1');
        Route::get('/billing', [BillSettlementController::class, 'index']);
        Route::get('/billing/reminders', [BillingInvoiceReminderController::class, 'index']);
        Route::post('/billing/reminders', [BillingInvoiceReminderController::class, 'store'])->middleware('throttle:20,1');
        Route::post('/billing/reminders/{reminder}/retry', [BillingInvoiceReminderController::class, 'retry'])->whereUuid('reminder')->middleware('throttle:20,1');
        Route::post('/billing/reminders/{reminder}/resolve', [BillingInvoiceReminderController::class, 'resolve'])->whereUuid('reminder')->middleware('throttle:20,1');
        Route::get('/billing/templates', [RecurringMemberInvoiceController::class, 'index']);
        Route::post('/billing/templates', [RecurringMemberInvoiceController::class, 'save'])->middleware('throttle:20,1');
        Route::post('/billing/templates/generate', [RecurringMemberInvoiceController::class, 'generate'])->middleware('throttle:5,1');
        Route::post('/billing/templates/{template}', [RecurringMemberInvoiceController::class, 'save'])->whereUuid('template')->middleware('throttle:20,1');
        Route::post('/billing/templates/{template}/state', [RecurringMemberInvoiceController::class, 'state'])->whereUuid('template')->middleware('throttle:20,1');
        Route::get('/billing/documents/{voucher}', [FinanceDocumentController::class, 'show'])->whereUuid('voucher');
        Route::post('/billing/documents', [BillSettlementController::class, 'save'])->middleware('throttle:20,1');
        Route::post('/billing/documents/{voucher}', [BillSettlementController::class, 'save'])->whereUuid('voucher')->middleware('throttle:20,1');
        Route::post('/billing/allocations', [BillSettlementController::class, 'allocate'])->middleware('throttle:20,1');
        Route::post('/billing/allocations/{allocation}/release', [BillSettlementController::class, 'release'])->whereUuid('allocation')->middleware('throttle:20,1');
        Route::post('/billing/bridge', [BillSettlementController::class, 'bridge'])->middleware('throttle:10,1');
        Route::get('/masters', [AccountingMastersController::class, 'index']);
        Route::post('/masters/groups', [AccountingMastersController::class, 'group'])->middleware('throttle:20,1');
        Route::post('/masters/vendors', [AccountingMastersController::class, 'vendor'])->middleware('throttle:20,1');
        Route::post('/masters/sync', [AccountingMastersController::class, 'sync'])->middleware('throttle:10,1');
        Route::post('/masters/ledgers/{account}/group', [AccountingMastersController::class, 'classify'])->whereUuid('account')->middleware('throttle:20,1');
        Route::get('/', [Finance::class, 'index'])->name('association-finance.index');
        foreach (['initialize', 'settings', 'account', 'sync', 'close'] as $action) {
            Route::post('/'.$action, [Finance::class, $action])->middleware('throttle:20,1');
        }
        Route::post('/vouchers', [Finance::class, 'save'])->middleware('throttle:20,1');
        Route::post('/vouchers/{voucher}', [Finance::class, 'save'])->whereUuid('voucher')->middleware('throttle:20,1');
        Route::post('/vouchers/{voucher}/decision', [Finance::class, 'decide'])->whereUuid('voucher')->middleware('throttle:20,1');
        Route::get('/vouchers/{voucher}/evidence', [Finance::class, 'evidence'])->whereUuid('voucher');
        Route::post('/lines/{line}/clear', [Finance::class, 'clear'])->whereNumber('line')->middleware('throttle:30,1');
        Route::get('/export', [Finance::class, 'export'])->middleware('throttle:5,1');
        Route::get('/tally', [TallyXmlExportController::class, 'index']);
        Route::post('/tally/mappings', [TallyXmlExportController::class, 'mapping'])->middleware('throttle:20,1');
        Route::get('/tally/download', [TallyXmlExportController::class, 'download'])->middleware('throttle:10,1');
    });
});
