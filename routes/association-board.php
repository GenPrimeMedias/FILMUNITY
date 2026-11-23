<?php

use App\Http\Controllers\BoardManagementController;
use App\Http\Middleware\ProtectWorkspaceMutation;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('association-board/{association}')
    ->whereUuid('association')
    ->name('association-board.')
    ->controller(BoardManagementController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('overview');
        Route::get('/setup', 'setup')->name('setup');
        Route::get('/terms', 'terms')->name('terms');
        Route::get('/meetings', 'meetings')->name('meetings');
        Route::get('/minutes', 'minutes')->name('minutes');
        Route::get('/actions', 'actions')->name('actions');
        Route::get('/directory', [BoardManagementController::class, 'directory'])->name('directory');
        Route::post('/setup', 'saveSetup')->middleware(ProtectWorkspaceMutation::class.':board')->name('setup.save');
        Route::post('/positions', 'savePositions')->middleware(ProtectWorkspaceMutation::class.':board')->name('positions.save');
        Route::post('/policy', 'savePolicy')->middleware(ProtectWorkspaceMutation::class.':board')->name('policy.save');
        Route::post('/terms', 'storeTerm')->middleware(ProtectWorkspaceMutation::class.':board')->name('terms.store');
        Route::patch('/terms/{term}', 'updateTerm')->whereUuid('term')->middleware(ProtectWorkspaceMutation::class.':board')->name('terms.update');
        Route::post('/appointments', 'storeAppointment')->middleware(ProtectWorkspaceMutation::class.':board')->name('appointments.store');
        Route::post('/appointments/{appointment}/portrait', 'portrait')->whereUuid('appointment')->middleware(ProtectWorkspaceMutation::class.':board')->name('appointments.portrait');
        Route::get('/appointments/{appointment}/portrait', 'portraitFile')->whereUuid('appointment')->name('appointments.portrait.file');
        Route::get('/member-avatar/{member}', 'memberAvatar')->whereNumber('member')->name('member-avatar');
        Route::patch('/appointments/{appointment}', 'updateAppointment')->whereUuid('appointment')->middleware(ProtectWorkspaceMutation::class.':board')->name('appointments.update');
        Route::post('/meetings', 'storeMeeting')->middleware(ProtectWorkspaceMutation::class.':board')->name('meetings.store');
        Route::post('/meetings/{meeting}/notice', 'sendMeetingNotice')->whereUuid('meeting')->middleware(ProtectWorkspaceMutation::class.':board')->name('meetings.notice');
        Route::post('/meetings/{meeting}/participants', 'storeParticipant')->whereUuid('meeting')->middleware(ProtectWorkspaceMutation::class.':board')->name('participants.store');
        Route::patch('/participants/{participant}', 'updateParticipant')->whereUuid('participant')->middleware(ProtectWorkspaceMutation::class.':board')->name('participants.update');
        Route::post('/meetings/{meeting}/agenda', 'storeAgendaItem')->whereUuid('meeting')->middleware(ProtectWorkspaceMutation::class.':board')->name('agenda.store');
        Route::post('/meetings/{meeting}/agenda/{agendaItem}/votes', 'recordVote')->whereUuid('meeting')->whereUuid('agendaItem')->middleware(ProtectWorkspaceMutation::class.':board')->name('votes.store');
        Route::post('/meetings/{meeting}/agenda/{agendaItem}/finalize', 'finalizeDecision')->whereUuid('meeting')->whereUuid('agendaItem')->middleware(ProtectWorkspaceMutation::class.':board')->name('decision.finalize');
        Route::post('/meetings/{meeting}/minutes', 'updateMinutes')->whereUuid('meeting')->middleware(ProtectWorkspaceMutation::class.':board')->name('minutes.update');
        Route::post('/reviewers', 'addReviewer')->middleware(ProtectWorkspaceMutation::class.':board')->name('reviewers.store');
        Route::patch('/governance-settings', 'updateGovernanceSettings')->middleware(ProtectWorkspaceMutation::class.':board')->name('settings.update');
        Route::post('/actions', 'storeAction')->middleware(ProtectWorkspaceMutation::class.':board')->name('actions.store');
        Route::patch('/actions/{action}', 'updateAction')->whereUuid('action')->middleware(ProtectWorkspaceMutation::class.':board')->name('actions.update');
        Route::patch('/actions/{action}/reassign', 'reassignAction')->whereUuid('action')->middleware(ProtectWorkspaceMutation::class.':board')->name('actions.reassign');
    });
