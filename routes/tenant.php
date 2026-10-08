<?php

use App\Core\Audit\Http\Controllers\AuditLogController;
use App\Core\Dashboard\Http\Controllers\DashboardController;
use App\Core\Http\Controllers\LocaleController;
use App\Core\Identity\Http\Controllers\Auth\InvitationAcceptanceController;
use App\Core\Identity\Http\Controllers\Auth\LoginController;
use App\Core\Identity\Http\Controllers\Auth\NewPasswordController;
use App\Core\Identity\Http\Controllers\Auth\PasswordResetLinkController;
use App\Core\Identity\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Core\Identity\Http\Controllers\Auth\WorkspaceHandoffController;
use App\Core\Identity\Http\Controllers\Settings\ProfileController;
use App\Core\Identity\Http\Controllers\Settings\SecurityController;
use App\Core\Identity\Http\Controllers\Settings\TwoFactorController;
use App\Core\Identity\Http\Controllers\Team\InvitationController;
use App\Core\Identity\Http\Controllers\Team\MemberController;
use App\Core\Identity\Http\Controllers\Team\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Core workspace routes - {tenant}.APP_DOMAIN
|--------------------------------------------------------------------------
|
| Loaded by CoreServiceProvider inside the `web` + `tenant` middleware groups,
| so the tenant is already resolved from the subdomain for every route here.
|
| Rules for anything added to a workspace:
|  - bind tenant-scoped models only (TenantMembership, never User);
|  - name the permission on the route with `can:`;
|  - a non-core module's routes also carry its `entitled:` middleware, which
|    ModuleServiceProvider::loadTenantRoutes() adds for you.
|
*/

Route::middleware('guest:web')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('auth.login');
    Route::post('login', [LoginController::class, 'store'])->name('auth.login.store');

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('auth.two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('auth.two-factor.verify');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('auth.password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('auth.password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.password.update');
});

// Reachable signed in or signed out.
Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('auth.invitations.show');
Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'store'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('auth.invitations.accept');

Route::get('auth/handoff', [WorkspaceHandoffController::class, 'consume'])
    ->middleware('throttle:20,1')
    ->name('auth.handoff');

Route::post('locale', LocaleController::class)->name('core.locale.update');

Route::middleware(['auth:web', 'auth.session', 'member'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('auth.logout');
    Route::post('workspaces/{workspace}/switch', [WorkspaceHandoffController::class, 'issue'])
        ->name('core.workspaces.switch');

    Route::get('/', DashboardController::class)->name('core.dashboard');

    // Team
    Route::get('team/members', [MemberController::class, 'index'])
        ->middleware('can:core.members.view')
        ->name('core.members.index');
    Route::patch('team/members/{membership}', [MemberController::class, 'update'])
        ->middleware('can:core.members.manage')
        ->name('core.members.update');
    Route::delete('team/members/{membership}', [MemberController::class, 'destroy'])
        ->middleware('can:core.members.manage')
        ->name('core.members.destroy');

    Route::post('team/invitations', [InvitationController::class, 'store'])
        ->middleware('can:core.members.invite')
        ->name('core.invitations.store');
    Route::delete('team/invitations/{invitation}', [InvitationController::class, 'destroy'])
        ->middleware('can:core.members.invite')
        ->name('core.invitations.destroy');

    // Roles
    Route::get('team/roles', [RoleController::class, 'index'])
        ->middleware('can:core.roles.view')
        ->name('core.roles.index');
    Route::middleware('can:core.roles.manage')->group(function () {
        Route::get('team/roles/create', [RoleController::class, 'create'])->name('core.roles.create');
        Route::post('team/roles', [RoleController::class, 'store'])->name('core.roles.store');
        Route::get('team/roles/{role}/edit', [RoleController::class, 'edit'])->name('core.roles.edit');
        Route::put('team/roles/{role}', [RoleController::class, 'update'])->name('core.roles.update');
        Route::delete('team/roles/{role}', [RoleController::class, 'destroy'])->name('core.roles.destroy');
    });

    // Audit trail
    Route::get('audit-log', [AuditLogController::class, 'index'])
        ->middleware('can:core.audit.view')
        ->name('core.audit.index');

    // Personal settings
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('core.settings.profile');
    Route::put('settings/profile', [ProfileController::class, 'update'])->name('core.settings.profile.update');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('core.settings.security');
    Route::put('settings/password', [SecurityController::class, 'updatePassword'])
        ->middleware('throttle:6,1')
        ->name('core.settings.password.update');

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('settings/two-factor', [TwoFactorController::class, 'store'])->name('core.settings.two-factor.enable');
        Route::post('settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('core.settings.two-factor.confirm');
        Route::post('settings/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->name('core.settings.two-factor.recovery-codes');
        Route::delete('settings/two-factor', [TwoFactorController::class, 'destroy'])->name('core.settings.two-factor.disable');
    });
});
