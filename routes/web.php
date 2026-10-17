<?php

use App\Http\Controllers\CurrentTenantController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('current-tenant', [CurrentTenantController::class, 'update'])->name('current-tenant.update');
});

require __DIR__.'/settings.php';
