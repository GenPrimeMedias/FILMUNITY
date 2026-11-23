<?php

use App\Http\Controllers\ApplicationCommunicationController;
use App\Http\Controllers\MembershipCardController;
use Illuminate\Support\Facades\Route;

Route::get('/verify-membership/{kind}/{token}', [MembershipCardController::class, 'verify'])->where('kind', 'fu|association')->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:60,1')->name('membership-cards.verify');
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('/membership-applications/{application}/retry-notices', [ApplicationCommunicationController::class, 'retry'])->whereUuid('application')->middleware('throttle:3,1')->name('membership-applications.retry-notices');
    Route::get('/membership-cards/filmunity', [MembershipCardController::class, 'platform'])->name('membership-cards.platform');
    Route::get('/membership-cards/association/{membership}', [MembershipCardController::class, 'association'])->whereUuid('membership')->name('membership-cards.association');
    Route::post('/membership-cards/passport-photo', [MembershipCardController::class, 'passport'])->middleware('throttle:10,1')->name('membership-cards.passport');
});
