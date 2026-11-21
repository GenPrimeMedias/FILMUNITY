<?php

use App\Http\Controllers\Auth\GoogleAuthenticationController;
use App\Http\Controllers\ProfessionalProfileController;
use App\Http\Controllers\PublicProfessionalProfileController;
use App\Http\Middleware\RedirectReviewerDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request) => redirect()->route($request->user() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('/auth/google/redirect', [GoogleAuthenticationController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthenticationController::class, 'callback'])->name('auth.google.callback');
});

Route::get('/community/@{username}', [PublicProfessionalProfileController::class, 'show'])
    ->where('username', '[a-z0-9_]{3,30}')->name('community.profile');

Route::get('/professional-profile/avatar', [ProfessionalProfileController::class, 'avatar'])
    ->middleware('auth')->name('professional-profile.avatar');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [ProfessionalProfileController::class, 'dashboard'])
        ->middleware(RedirectReviewerDashboard::class)
        ->name('dashboard');
    Route::get('/professional-profile/username-availability', [ProfessionalProfileController::class, 'usernameAvailability'])
        ->middleware('throttle:60,1')->name('professional-profile.username-availability');
    Route::post('/professional-profile/publication', [PublicProfessionalProfileController::class, 'publish'])
        ->name('professional-profile.publish');
    Route::delete('/professional-profile/publication', [PublicProfessionalProfileController::class, 'unpublish'])
        ->name('professional-profile.unpublish');
    Route::put('/professional-profile/draft', [ProfessionalProfileController::class, 'store'])->name('professional-profile.draft');
});

require __DIR__.'/settings.php';

require __DIR__.'/association-registration.php';

require __DIR__.'/membership-applications.php';

require __DIR__.'/association-payments.php';
require __DIR__.'/membership-renewals.php';

require __DIR__.'/membership-renewal-queue.php';

require __DIR__.'/individual-membership.php';

require __DIR__.'/association-finance.php';

require __DIR__.'/association-help.php';

require __DIR__.'/application-print.php';

require __DIR__.'/workspace-controls.php';
