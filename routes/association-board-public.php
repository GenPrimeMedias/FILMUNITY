<?php

use App\Http\Controllers\BoardManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/association-board/{association}/public-directory', [BoardManagementController::class, 'publicDirectory'])->whereUuid('association')->name('association-board.public-directory');
