<?php

use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalHomeController;
use Illuminate\Support\Facades\Route;

/*
 * Step 7a.1a (ADR-0012): the patient portal. Its network comes from the address; it never uses the staff
 * guard, and the staff routes never accept the patient guard.
 */
Route::prefix('portal/{portal}')->middleware('portal')->name('portal.')->group(function () {
    Route::get('entrar', [PortalAuthController::class, 'showLogin'])->name('login');
    Route::post('entrar', [PortalAuthController::class, 'login'])->middleware('throttle:20,1')->name('login.store');
    Route::get('esqueci-a-senha', [PortalAuthController::class, 'showForgot'])->name('forgot');
    Route::post('esqueci-a-senha', [PortalAuthController::class, 'forgot'])->middleware('throttle:5,1')->name('forgot.store');
    Route::get('criar-senha/{token}', [PortalAuthController::class, 'showSetup'])->middleware('throttle:20,1')->name('setup');
    Route::post('criar-senha/{token}', [PortalAuthController::class, 'setup'])->middleware('throttle:10,1')->name('setup.store');

    Route::middleware('auth:patient')->group(function () {
        Route::get('/', PortalHomeController::class)->name('home');
        Route::post('sair', [PortalAuthController::class, 'logout'])->name('logout');
    });
});
