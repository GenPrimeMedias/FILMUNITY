<?php

use App\Http\Controllers\AssociationWorkspaceSetupController;
use App\Http\Controllers\FilmunityAdminController;
use App\Http\Controllers\MembershipApplicationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->controller(MembershipApplicationController::class)->group(function (): void {
    Route::get('/membership-applications', 'index')->name('membership-applications.index');
    Route::post('/membership-applications', 'store')->middleware('throttle:10,1')->name('membership-applications.store');
    Route::get('/membership-applications/{application}/documents/{field}', 'document')
        ->whereUuid('application')->whereUuid('field')->name('membership-applications.document');
    Route::get('/association-membership-review', 'queue')->name('association-membership-review.index');
    Route::patch('/association-membership-review/{application}', 'decide')->whereUuid('application')
        ->name('association-membership-review.decide');
});

Route::middleware(['auth', 'verified'])->controller(AssociationWorkspaceSetupController::class)
    ->group(function (): void {
        Route::get('/association-workspaces/{workspace}/setup', 'edit')->whereUuid('workspace')
            ->name('association-workspace-setup.edit');
        Route::post('/association-workspaces/{workspace}/setup', 'update')->whereUuid('workspace')
            ->name('association-workspace-setup.update');
        Route::get('/membership-applications/{association}/logo', 'logo')->whereUuid('association')
            ->name('membership-applications.logo');
    });

Route::get('/filmunity-admin/membership-applications/{application}/documents/{field}',
    [FilmunityAdminController::class, 'membershipDocument'])
    ->middleware(['auth', 'verified'])->whereUuid('application')->whereUuid('field')
    ->name('filmunity-admin.membership-document');
