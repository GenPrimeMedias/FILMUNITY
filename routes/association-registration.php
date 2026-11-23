<?php

use App\Http\Controllers\AssociationRegistrationController;
use App\Http\Controllers\AssociationWorkspaceController;
use App\Http\Controllers\FilmunityAdminController;
use Illuminate\Support\Facades\Route;

Route::get('/association-registration/start', [AssociationRegistrationController::class, 'start'])
    ->name('association-registration.start');

Route::middleware(['auth', 'verified'])
    ->prefix('association-registration')
    ->name('association-registration.')
    ->controller(AssociationRegistrationController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
        Route::get('/review', 'reviewQueue')->name('review');
        Route::get('/{application}/certificate', 'certificate')->whereUuid('application')->name('certificate');
        Route::post('/{application}/approval-email', 'sendApprovalEmail')->whereUuid('application')->middleware('throttle:10,1')->name('approval-email');
        Route::patch('/{application}/review', 'review')->whereUuid('application')->name('decision');
    });

Route::prefix('filmunity-admin')->name('filmunity-admin.')->group(function (): void {
    Route::get('/login', [FilmunityAdminController::class, 'login'])->name('login');

    Route::middleware(['auth', 'verified'])->group(function (): void {
        Route::get('/', [FilmunityAdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/members', [FilmunityAdminController::class, 'members'])->name('members');
    });
});

Route::middleware(['auth', 'verified'])->prefix('association-workspaces')
    ->name('association-workspaces.')->controller(AssociationWorkspaceController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/{workspace}', 'show')->whereUuid('workspace')->name('show');
    });
