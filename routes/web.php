<?php

use App\Http\Controllers\CurrentTenantController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('units', UnitController::class)->except('destroy');
    Route::scopeBindings()->group(function () {
        Route::post('units/{unit}/sectors', [SectorController::class, 'store'])->name('units.sectors.store');
        Route::put('units/{unit}/sectors/{sector}', [SectorController::class, 'update'])->name('units.sectors.update');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('current-tenant', [CurrentTenantController::class, 'edit'])->name('current-tenant.edit');
    Route::post('current-tenant', [CurrentTenantController::class, 'update'])->name('current-tenant.update');
});

require __DIR__.'/settings.php';
