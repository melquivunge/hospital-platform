<?php

use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalChildController;
use App\Http\Controllers\Portal\PortalDocumentController;
use App\Http\Controllers\Portal\PortalHomeController;
use App\Http\Controllers\Portal\PortalMyDataController;
use App\Http\Controllers\Portal\PortalResultPdfController;
use App\Http\Controllers\Portal\PortalSignupController;
use App\Http\Controllers\Portal\PortalTwoFactorController;
use App\Http\Controllers\Portal\PortalVisitController;
use App\Http\Controllers\Portal\PortalVisitsController;
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

    Route::get('primeiro-acesso', [PortalSignupController::class, 'show'])->name('signup');
    Route::post('primeiro-acesso', [PortalSignupController::class, 'start'])->middleware('throttle:5,1')->name('signup.store');
    Route::get('primeiro-acesso/codigo', [PortalSignupController::class, 'showCode'])->name('signup.code');
    Route::post('primeiro-acesso/codigo', [PortalSignupController::class, 'verify'])->middleware('throttle:10,1')->name('signup.verify');

    // Step 7b.1: the arrival's journey by QR, without an account. Per IP: a waiting room behind one Wi-Fi polls every 20 s (7b.2).
    Route::get('visita/{token}', PortalVisitController::class)->middleware('throttle:240,1')->name('visit');
    Route::get('entrar/codigo', [PortalTwoFactorController::class, 'showChallenge'])->name('challenge');
    Route::post('entrar/codigo', [PortalTwoFactorController::class, 'challenge'])->middleware('throttle:10,1')->name('challenge.store');

    Route::middleware('auth:patient')->group(function () {
        Route::get('/', PortalHomeController::class)->name('home');
        Route::get('visitas', PortalVisitsController::class)->name('visits');
        Route::get('meus-dados', [PortalMyDataController::class, 'show'])->name('my-data');
        Route::post('meus-dados', [PortalMyDataController::class, 'store'])->middleware('throttle:5,1')->name('my-data.store');
        Route::get('documentos/{document}', PortalDocumentController::class)->whereNumber('document')->middleware('throttle:30,1')->name('document');
        Route::get('resultados/{result}/pdf', PortalResultPdfController::class)->whereNumber('result')->middleware('throttle:30,1')->name('result.pdf');
        Route::get('filhos/{patient}', PortalChildController::class)->whereNumber('patient')->name('child');
        Route::get('seguranca', [PortalTwoFactorController::class, 'show'])->name('security');
        Route::middleware('throttle:6,1')->group(function () {
            Route::post('seguranca/duas-etapas', [PortalTwoFactorController::class, 'enable'])->name('two-factor.enable');
            Route::post('seguranca/duas-etapas/confirmar', [PortalTwoFactorController::class, 'confirm'])->name('two-factor.confirm');
            Route::post('seguranca/codigos-de-recuperacao', [PortalTwoFactorController::class, 'regenerate'])->name('two-factor.recovery-codes');
            Route::delete('seguranca/duas-etapas', [PortalTwoFactorController::class, 'disable'])->name('two-factor.disable');
        });
        Route::post('sair', [PortalAuthController::class, 'logout'])->name('logout');
    });
});
