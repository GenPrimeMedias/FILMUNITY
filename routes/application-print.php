<?php

use App\Http\Controllers\ApplicationPrintController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/association-registration/{application}/print', [ApplicationPrintController::class, 'registration'])
        ->whereUuid('application')->name('association-registration.print');
    Route::get('/membership-applications/{application}/print', [ApplicationPrintController::class, 'membership'])
        ->whereUuid('application')->name('membership-applications.print');
});
