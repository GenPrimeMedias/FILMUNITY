<?php

use App\Http\Controllers\WorkspaceProtectionController;
use App\Http\Controllers\AssociationMailController;
use Illuminate\Support\Facades\Route;

Route::prefix('/association-workspaces/{workspace}/protection')->whereUuid('workspace')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [WorkspaceProtectionController::class, 'status']);
    Route::post('/confirm', [WorkspaceProtectionController::class, 'confirm'])->middleware('throttle:5,1');
    Route::post('/pin', [WorkspaceProtectionController::class, 'pin'])->middleware('throttle:5,1');
    Route::post('/unlock', [WorkspaceProtectionController::class, 'unlock'])->middleware('throttle:6,1');
    Route::post('/lease', [WorkspaceProtectionController::class, 'lease'])->middleware('throttle:120,1');
});

Route::prefix('/association-workspaces/{workspace}/mail')->whereUuid('workspace')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [AssociationMailController::class, 'show']);
    Route::post('/', [AssociationMailController::class, 'save'])->middleware('throttle:10,1');
    Route::post('/test', [AssociationMailController::class, 'test'])->middleware('throttle:3,1');
});
