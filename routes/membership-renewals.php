<?php

use App\Http\Controllers\AssociationMemberController;
use App\Http\Controllers\MembershipRenewalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/membership-renewals', [AssociationMemberController::class, 'index'])->name('membership-renewals.index');
    Route::get('/membership-renewals/{membership}', [MembershipRenewalController::class, 'show'])->whereUuid('membership')->name('membership-renewals.show');
    Route::post('/membership-renewals/{membership}', [MembershipRenewalController::class, 'store'])->whereUuid('membership')->middleware('throttle:10,1')->name('membership-renewals.store');
});
