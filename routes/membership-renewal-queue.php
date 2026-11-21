<?php
use App\Http\Controllers\MembershipApplicationController;
use App\Http\Controllers\MembershipReminderController;
use Illuminate\Support\Facades\Route;
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/membership-applications/apply', [MembershipApplicationController::class, 'apply'])->name('membership-applications.apply');
    Route::post('/association-members/{membership}/renewal-reminder', [MembershipReminderController::class, 'store'])
        ->whereUuid('membership')->middleware('throttle:10,1')->name('association-members.renewal-reminder');
});
