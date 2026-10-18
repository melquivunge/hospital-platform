<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceOccurrenceController;
use App\Http\Controllers\AttendancePointController;
use App\Http\Controllers\CurrentTenantController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\JobFunctionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ProfessionalController;
use App\Http\Controllers\QualificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ShiftRequestController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\VacancyController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('units', UnitController::class)->except('destroy');
    Route::resource('professionals', ProfessionalController::class)->except('destroy');
    Route::post('professionals/{professional}/qualifications', [QualificationController::class, 'store'])->name('professionals.qualifications.store');
    Route::post('qualifications/{qualification}/transition', [QualificationController::class, 'transition'])->name('qualifications.transition');
    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::get('imports/create', [ImportController::class, 'create'])->name('imports.create');
    Route::get('imports/template', [ImportController::class, 'template'])->name('imports.template');
    Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
    Route::get('imports/{batch}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('imports/{batch}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
    Route::get('my-shifts', [ShiftController::class, 'mine'])->name('shifts.mine');
    Route::get('vacancies', [VacancyController::class, 'index'])->name('vacancies.index');
    Route::get('vacancies/{shift}', [VacancyController::class, 'show'])->name('vacancies.show');
    Route::post('vacancies/{shift}/apply', [VacancyController::class, 'apply'])->name('vacancies.apply');
    Route::post('vacancies/{shift}/withdraw', [VacancyController::class, 'withdraw'])->name('vacancies.withdraw');
    Route::post('my-shifts/{shift}/announce', [VacancyController::class, 'announce'])->name('shifts.announce');
    Route::post('my-shifts/{shift}/cancel-announcement', [VacancyController::class, 'cancelAnnouncement'])->name('shifts.cancel-announcement');
    Route::get('my-shifts/{shift}/transfer', [ShiftRequestController::class, 'createTransfer'])->name('shifts.transfer.create');
    Route::post('my-shifts/{shift}/transfer', [ShiftRequestController::class, 'storeTransfer'])->name('shifts.transfer.store');
    Route::get('my-shifts/{shift}/swap', [ShiftRequestController::class, 'createSwap'])->name('shifts.swap.create');
    Route::post('my-shifts/{shift}/swap', [ShiftRequestController::class, 'storeSwap'])->name('shifts.swap.store');
    // Rejected attempts are kept forever (append-only): bound how fast one person can create them.
    Route::post('shifts/{shift}/attendance', [AttendanceController::class, 'store'])->middleware('throttle:20,1')->name('shifts.attendance');
    Route::post('units/{unit}/attendance-points', [AttendancePointController::class, 'store'])->name('units.attendance-points.store');
    Route::post('shifts/{shift}/occurrences', [AttendanceOccurrenceController::class, 'store'])->middleware('throttle:20,1')->name('shifts.occurrences.store');
    Route::post('occurrences/{occurrence}/decide', [AttendanceOccurrenceController::class, 'decide'])->name('occurrences.decide');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/schedule.csv', [ReportController::class, 'schedule'])->middleware('throttle:20,1')->name('reports.schedule');
    Route::get('reports/attendance.csv', [ReportController::class, 'attendance'])->middleware('throttle:20,1')->name('reports.attendance');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('requests', [ShiftRequestController::class, 'index'])->name('requests.index');
    Route::post('requests/{shiftRequest}/respond', [ShiftRequestController::class, 'respond'])->name('requests.respond');
    Route::post('requests/{shiftRequest}/cancel', [ShiftRequestController::class, 'cancel'])->name('requests.cancel');
    Route::post('requests/{shiftRequest}/decide', [ShiftRequestController::class, 'decide'])->name('requests.decide');
    Route::post('shift-applications/{application}/select', [ShiftController::class, 'select'])->name('shift-applications.select');
    Route::post('professionals/{professional}/invite', [ProfessionalController::class, 'invite'])->name('professionals.invite');
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
        Route::post('units/{unit}/attendance-points/{attendancePoint}/deactivate', [AttendancePointController::class, 'deactivate'])->name('units.attendance-points.deactivate');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('current-tenant', [CurrentTenantController::class, 'edit'])->name('current-tenant.edit');
    Route::post('current-tenant', [CurrentTenantController::class, 'update'])->name('current-tenant.update');
});

require __DIR__.'/settings.php';
