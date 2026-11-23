<?php

use App\Http\Controllers\AssociationMemberController;
use App\Http\Controllers\AssociationPaymentController;
use App\Http\Controllers\LegacyMembershipPreviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/association-members/legacy-preview', [LegacyMembershipPreviewController::class, 'index']);
    Route::get('/association-members/{association}/legacy-template', [LegacyMembershipPreviewController::class, 'template'])->whereUuid('association');
    Route::post('/association-members/{association}/legacy-preview', [LegacyMembershipPreviewController::class, 'preview'])->whereUuid('association')->middleware('throttle:10,1');
    Route::controller(AssociationPaymentController::class)->group(function (): void {
        Route::get('/membership-payments', 'index')->name('membership-payments.index');
        Route::get('/association-payments', 'index')->defaults('scope', 'admin')->name('association-payments.index');
        Route::get('/filmunity-admin/payments', 'index')->defaults('scope', 'audit')->name('filmunity-admin.payments');
        Route::post('/membership-payments/request/{application}', 'create')->whereUuid('application')
            ->middleware('throttle:10,1')->name('association-payments.request');
        Route::get('/membership-payments/{payment}', 'show')->whereUuid('payment')->name('membership-payments.show');
        Route::post('/membership-payments/{payment}/submissions', 'submit')->whereUuid('payment')
            ->middleware('throttle:10,1')->name('membership-payments.submit');
        Route::patch('/association-payments/{payment}/submissions/{submission}', 'decide')
            ->whereUuid('payment')->whereUuid('submission')->name('association-payments.decide');
        Route::post('/association-payments/{payment}/zero-fee', 'zeroFee')->whereUuid('payment')->name('association-payments.zero-fee');
        Route::get('/membership-payments/{payment}/submissions/{submission}/proof', 'proof')
            ->whereUuid('payment')->whereUuid('submission')->name('membership-payments.proof');
        Route::get('/membership-receipts/{receipt}', 'receipt')->whereUuid('receipt')->name('membership-payments.receipt');
        Route::post('/membership-payments/{payment}/retry-emails', 'retryEmails')->whereUuid('payment')
            ->middleware('throttle:3,1')->name('membership-payments.retry-emails');
    });
    Route::controller(AssociationMemberController::class)->group(function (): void {
        Route::post('/membership-applications/{application}/confirm-plan', 'confirmPlan')->whereUuid('application')
            ->name('membership-applications.confirm-plan');
        Route::get('/my-association-workspaces', 'index')->name('association-memberships.index');
        Route::get('/association-members', 'index')->defaults('scope', 'admin')->name('association-members.index');
        Route::get('/association-member-workspaces/{membership}', 'workspace')->whereUuid('membership')
            ->name('association-memberships.workspace');
    });
});
