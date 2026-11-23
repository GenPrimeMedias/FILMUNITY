<?php

use App\Http\Controllers\AssociationHelpController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/association-help/{section}/download', [AssociationHelpController::class, 'download'])->where('section', 'profile|association|members|finance|board')->name('association-help.download');
    Route::get('/association-help/{section?}', [AssociationHelpController::class, 'index'])->where('section', 'profile|association|members|finance|board')->name('association-help.index');
});
