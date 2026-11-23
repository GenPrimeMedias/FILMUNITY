<?php

use App\Http\Controllers\AssociationMailController;
use App\Http\Controllers\Auth\GoogleAuthenticationController;
use App\Http\Controllers\WorkspaceProtectionController;
use Illuminate\Support\Facades\Route;

Route::prefix('/association-workspaces/{workspace}/protection')->whereUuid('workspace')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [WorkspaceProtectionController::class, 'status']);
    Route::post('/confirm', [WorkspaceProtectionController::class, 'confirm'])->middleware('throttle:workspace-confirm');
    Route::post('/pin', [WorkspaceProtectionController::class, 'pin'])->middleware('throttle:workspace-pin');
    Route::post('/disable', [WorkspaceProtectionController::class, 'disable'])->middleware('throttle:workspace-pin');
    Route::post('/enable', [WorkspaceProtectionController::class, 'enable'])->middleware('throttle:workspace-pin');
    Route::post('/unlock', [WorkspaceProtectionController::class, 'unlock'])->middleware('throttle:workspace-unlock');
    Route::post('/lease', [WorkspaceProtectionController::class, 'lease'])->middleware('throttle:workspace-lease');
});

Route::middleware(['auth', 'verified', 'throttle:workspace-google'])->group(function () {
    Route::get('/workspace-security/google/redirect', [GoogleAuthenticationController::class, 'workspaceReauthentication'])->name('workspace-google.redirect');
    Route::get('/workspace-security/google/callback', [GoogleAuthenticationController::class, 'callback'])->name('workspace-google.callback');
});

Route::prefix('/association-workspaces/{workspace}/mail')->whereUuid('workspace')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [AssociationMailController::class, 'show']);
    Route::post('/', [AssociationMailController::class, 'save'])->middleware('throttle:workspace-mail-save');
    Route::post('/test', [AssociationMailController::class, 'test'])->middleware('throttle:workspace-mail-test');
    Route::post('/review', [AssociationMailController::class, 'review'])->middleware('throttle:workspace-mail-review');
});
