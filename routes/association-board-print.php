<?php

use App\Http\Controllers\BoardPrintController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('association-board/{association}')->whereUuid('association')->group(function (): void {
    Route::get('/print/{kind}', [BoardPrintController::class, 'show'])->name('association-board.print');
    Route::get('/meetings/{meeting}/calendar', [BoardPrintController::class, 'calendar'])->whereUuid('meeting')->name('association-board.calendar');
});
