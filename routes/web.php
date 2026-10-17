<?php

use App\Http\Controllers\CurrentTenantController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\JobFunctionController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ProfessionalController;
use App\Http\Controllers\QualificationController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('units', UnitController::class)->except('destroy');
    Route::resource('professionals', ProfessionalController::class)->except('destroy');
    Route::post('professionals/{professional}/qualifications', [QualificationController::class, 'store'])->name('professionals.qualifications.store');
    Route::post('qualifications/{qualification}/transition', [QualificationController::class, 'transition'])->name('qualifications.transition');
    Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
    Route::get('units/{unit}/shifts/create', [ShiftController::class, 'create'])->name('units.shifts.create');
    Route::post('units/{unit}/shifts', [ShiftController::class, 'store'])->name('units.shifts.store');
    Route::get('shifts/{shift}', [ShiftController::class, 'show'])->name('shifts.show');
    Route::get('shifts/{shift}/edit', [ShiftController::class, 'edit'])->name('shifts.edit');
    Route::put('shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
    Route::post('shifts/{shift}/publish', [ShiftController::class, 'publish'])->name('shifts.publish');
    Route::post('shifts/{shift}/cancel', [ShiftController::class, 'cancel'])->name('shifts.cancel');
    Route::get('job-functions', [JobFunctionController::class, 'index'])->name('job-functions.index');
    Route::post('job-functions', [JobFunctionController::class, 'store'])->name('job-functions.store');
    Route::put('job-functions/{jobFunction}', [JobFunctionController::class, 'update'])->name('job-functions.update');
    Route::get('people', [PeopleController::class, 'index'])->name('people.index');
    Route::post('people', [PeopleController::class, 'store'])->name('people.store');
    Route::get('people/{membership}', [PeopleController::class, 'show'])->name('people.show');
    Route::post('people/{membership}/revoke', [PeopleController::class, 'revoke'])->name('people.revoke');
    Route::post('people/{membership}/reactivate', [PeopleController::class, 'reactivate'])->name('people.reactivate');
    Route::post('people/{membership}/roles', [PeopleController::class, 'grantRole'])->name('people.roles.store');
    Route::get('policies', [PolicyController::class, 'index'])->name('policies.index');
    Route::post('policies/{key}', [PolicyController::class, 'publishNetwork'])->name('policies.publish');
    Route::post('units/{unit}/policies/{key}', [PolicyController::class, 'publishLocal'])->name('units.policies.publish');

    Route::scopeBindings()->group(function () {
        Route::delete('people/{membership}/roles/{roleAssignment}', [PeopleController::class, 'revokeRole'])->name('people.roles.destroy');
        Route::post('units/{unit}/sectors', [SectorController::class, 'store'])->name('units.sectors.store');
        Route::put('units/{unit}/sectors/{sector}', [SectorController::class, 'update'])->name('units.sectors.update');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('current-tenant', [CurrentTenantController::class, 'edit'])->name('current-tenant.edit');
    Route::post('current-tenant', [CurrentTenantController::class, 'update'])->name('current-tenant.update');
});

require __DIR__.'/settings.php';
