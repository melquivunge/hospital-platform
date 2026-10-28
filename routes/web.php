<?php

use App\Http\Controllers\AdmissionController;
use App\Http\Controllers\AihRequestController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceOccurrenceController;
use App\Http\Controllers\AttendancePointController;
use App\Http\Controllers\BedController;
use App\Http\Controllers\BillingAccountController;
use App\Http\Controllers\BillingLotController;
use App\Http\Controllers\BillingPriceController;
use App\Http\Controllers\BillingReportController;
use App\Http\Controllers\BpaFileController;
use App\Http\Controllers\CensusController;
use App\Http\Controllers\Cid10Controller;
use App\Http\Controllers\ClinicalDocumentController;
use App\Http\Controllers\ClinicalRecordController;
use App\Http\Controllers\CurrentTenantController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmergencyController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\ExamOrderController;
use App\Http\Controllers\ExamOverdueController;
use App\Http\Controllers\ExamQueueController;
use App\Http\Controllers\ExamResultController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InsurerController;
use App\Http\Controllers\JobFunctionController;
use App\Http\Controllers\MedicationAdministrationController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PatientAllergyController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientMergeController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PharmacyController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\PrescriptionSignatureController;
use App\Http\Controllers\ProfessionalController;
use App\Http\Controllers\QualificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ShiftRequestController;
use App\Http\Controllers\SigtapController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockIndicatorController;
use App\Http\Controllers\StockInventoryController;
use App\Http\Controllers\StockReportController;
use App\Http\Controllers\SurgeryCatalogController;
use App\Http\Controllers\SurgeryChecklistController;
use App\Http\Controllers\SurgeryDescriptionController;
use App\Http\Controllers\SurgeryMaterialController;
use App\Http\Controllers\SurgeryOrderController;
use App\Http\Controllers\SurgeryOverdueController;
use App\Http\Controllers\SurgeryRecordController;
use App\Http\Controllers\SurgeryScheduleController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TriageLevelController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\VacancyController;
use App\Http\Controllers\VisitController;
use App\Http\Controllers\WardController;
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
    Route::get('schedules', [ScheduleController::class, 'index'])->name('schedules.index');
    Route::post('units/{unit}/schedule-rules', [ScheduleController::class, 'storeRule'])->name('schedules.rules.store');
    Route::post('units/{unit}/schedule-rules/{rule}/deactivate', [ScheduleController::class, 'deactivateRule'])->name('schedules.rules.deactivate');
    Route::post('units/{unit}/schedule-blocks', [ScheduleController::class, 'storeBlock'])->name('schedules.blocks.store');
    Route::post('units/{unit}/schedule-blocks/{block}/cancel', [ScheduleController::class, 'cancelBlock'])->name('schedules.blocks.cancel');
    Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::post('units/{unit}/appointments', [AppointmentController::class, 'store'])->middleware('throttle:60,1')->name('appointments.store');
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->middleware('throttle:60,1')->name('appointments.cancel');
    Route::get('patients/{patient}/record', [ClinicalRecordController::class, 'show'])->name('clinical-record.show');
    Route::get('patients/{patient}/emergency', [ClinicalRecordController::class, 'create'])->name('clinical-record.emergency');
    Route::post('patients/{patient}/emergency', [ClinicalRecordController::class, 'store'])->middleware('throttle:3,10')->name('clinical-record.emergency.store');
    Route::get('clinical-access', [ClinicalRecordController::class, 'accessLog'])->name('clinical-access.index');
    Route::post('visits/{visit}/documents', [ClinicalDocumentController::class, 'store'])->middleware('throttle:30,1')->name('clinical-documents.store');
    Route::get('clinical-documents/{document}/download', [ClinicalDocumentController::class, 'download'])->middleware('throttle:60,1')->name('clinical-documents.download');
    Route::post('clinical-documents/{document}/withdraw', [ClinicalDocumentController::class, 'withdraw'])->middleware('throttle:30,1')->name('clinical-documents.withdraw');
    Route::post('patients/{patient}/allergies', [PatientAllergyController::class, 'store'])->middleware('throttle:30,1')->name('patient-allergies.store');
    Route::post('patient-allergies/{allergy}/inactivate', [PatientAllergyController::class, 'inactivate'])->middleware('throttle:30,1')->name('patient-allergies.inactivate');
    Route::post('ward/{admission}/prescriptions', [PrescriptionController::class, 'startForAdmission'])->middleware('throttle:30,1')->name('ward.prescriptions.start');
    Route::post('visits/{visit}/prescriptions', [PrescriptionController::class, 'startForVisit'])->middleware('throttle:30,1')->name('visits.prescriptions.start');
    Route::get('prescriptions/{prescription}/edit', [PrescriptionController::class, 'edit'])->middleware('throttle:120,1')->name('prescriptions.edit');
    Route::post('prescriptions/{prescription}/items', [PrescriptionController::class, 'storeItem'])->middleware('throttle:60,1')->name('prescriptions.items.store');
    Route::post('prescription-items/{item}/suspend', [PrescriptionController::class, 'suspendItem'])->middleware('throttle:30,1')->name('prescription-items.suspend');
    Route::post('prescription-items/{item}/justify', [PrescriptionController::class, 'justifyItem'])->middleware('throttle:60,1')->name('prescription-items.justify');
    Route::delete('prescription-items/{item}', [PrescriptionController::class, 'destroyItem'])->middleware('throttle:60,1')->name('prescription-items.destroy');
    Route::post('prescription-doses/{dose}/record', [MedicationAdministrationController::class, 'dose'])->where('dose', '[0-9]{1,15}')->middleware('throttle:60,1')->name('medication.dose');
    Route::post('prescription-items/{item}/administer', [MedicationAdministrationController::class, 'prn'])->middleware('throttle:60,1')->name('medication.prn');
    Route::delete('prescriptions/{prescription}', [PrescriptionController::class, 'discard'])->middleware('throttle:30,1')->name('prescriptions.discard');
    Route::post('prescriptions/{prescription}/sign', [PrescriptionController::class, 'sign'])->middleware('throttle:30,1')->name('prescriptions.sign');
    // Step 4b.5 (ADR-0006): digital signature with the linked certificate.
    Route::post('prescriptions/{prescription}/certify', [PrescriptionSignatureController::class, 'store'])->middleware('throttle:10,1')->name('prescriptions.certify');
    Route::get('prescriptions/{prescription}/signature/{part}', [PrescriptionSignatureController::class, 'download'])->whereIn('part', ['pdf', 'p7s'])->middleware('throttle:60,1')->name('prescriptions.signature');
    Route::get('settings/certificate', [PrescriptionSignatureController::class, 'edit'])->name('certificate.edit');
    Route::post('settings/certificate', [PrescriptionSignatureController::class, 'link'])->middleware('throttle:10,1')->name('certificate.link');
    Route::delete('settings/certificate', [PrescriptionSignatureController::class, 'unlink'])->middleware('throttle:10,1')->name('certificate.unlink');
    Route::get('billing-accounts', [BillingAccountController::class, 'index'])->middleware('throttle:120,1')->name('billing-accounts.index');
    Route::get('billing-accounts/{account}', [BillingAccountController::class, 'show'])->middleware('throttle:120,1')->name('billing-accounts.show');
    Route::post('billing-accounts/{account}/items', [BillingAccountController::class, 'storeItem'])->middleware('throttle:60,1')->name('billing-accounts.items.store');
    Route::post('billing-accounts/{account}/authorizations', [BillingAccountController::class, 'authorizeItems'])->middleware('throttle:60,1')->name('billing-accounts.authorizations.store');
    // Step 6b.5b1: the authorized AIH's number.
    Route::post('billing-accounts/{account}/aih-number', [BillingAccountController::class, 'recordAihNumber'])->middleware('throttle:30,1')->name('billing-accounts.aih-number');
    // Step 6b.5b2: billing changes the AIH's principal procedure.
    Route::post('billing-accounts/{account}/aih-principal', [BillingAccountController::class, 'changeAihPrincipal'])->middleware('throttle:30,1')->name('billing-accounts.aih-principal');
    Route::post('billing-accounts/{account}/close', [BillingAccountController::class, 'close'])->middleware('throttle:30,1')->name('billing-accounts.close');
    Route::post('billing-accounts/{account}/payment', [BillingAccountController::class, 'receive'])->middleware('throttle:30,1')->name('billing-accounts.payment.store');
    Route::get('billing-accounts/{account}/receipt', [BillingAccountController::class, 'receipt'])->middleware('throttle:30,1')->name('billing-accounts.receipt');
    Route::post('billing-account-items/{item}/compensate', [BillingAccountController::class, 'compensate'])->middleware('throttle:60,1')->name('billing-account-items.compensate');
    // Step 6b.6a2: the unit's BPA file of a competence (SUS).
    Route::get('sus-bpa', [BpaFileController::class, 'index'])->middleware('throttle:120,1')->name('sus-bpa.index');
    Route::post('sus-bpa', [BpaFileController::class, 'store'])->middleware('throttle:10,1')->name('sus-bpa.store');
    Route::get('sus-bpa/{file}/download', [BpaFileController::class, 'download'])->middleware('throttle:30,1')->name('sus-bpa.download');
    // Step 6.5b1: the TISS lots of a unit.
    Route::get('billing-lots', [BillingLotController::class, 'index'])->middleware('throttle:120,1')->name('billing-lots.index');
    Route::post('billing-lots', [BillingLotController::class, 'store'])->middleware('throttle:30,1')->name('billing-lots.store');
    Route::post('billing-lots/{lot}/cancel', [BillingLotController::class, 'cancel'])->middleware('throttle:30,1')->name('billing-lots.cancel');
    // Step 6.5b2a: the lot's TISS file.
    Route::post('billing-lots/{lot}/file', [BillingLotController::class, 'file'])->middleware('throttle:30,1')->name('billing-lots.file');
    Route::get('billing-lots/{lot}/file', [BillingLotController::class, 'download'])->middleware('throttle:30,1')->name('billing-lots.download');
    // Step 6.5c1: the insurer's return of a sent lot.
    Route::get('billing-lots/{lot}/return', [BillingLotController::class, 'showReturn'])->middleware('throttle:120,1')->name('billing-lots.return');
    Route::post('billing-lots/{lot}/return', [BillingLotController::class, 'storeReturn'])->middleware('throttle:30,1')->name('billing-lots.return.store');
    // Step 6b.2: the CID-10 search of the code fields.
    Route::get('cid10', Cid10Controller::class)->middleware('throttle:60,1')->name('cid10.search');
    // Step 6.6: the unit manager's billing reports (numbers only) and their CSV.
    Route::get('billing-reports', [BillingReportController::class, 'index'])->middleware('throttle:120,1')->name('billing-reports.index');
    Route::get('billing-reports.csv', [BillingReportController::class, 'csv'])->middleware('throttle:20,1')->name('billing-reports.csv');
    // Step 6.5c2: the glosa appeal, its file and the insurer's answer.
    Route::post('billing-lots/{lot}/appeals', [BillingLotController::class, 'storeAppeal'])->middleware('throttle:30,1')->name('billing-lots.appeals.store');
    Route::post('billing-lots/{lot}/appeals/results', [BillingLotController::class, 'storeAppealResults'])->middleware('throttle:30,1')->name('billing-lots.appeals.results');
    Route::get('billing-lots/{lot}/appeals/{sequence}/file', [BillingLotController::class, 'downloadAppeal'])->whereNumber('sequence')->middleware('throttle:30,1')->name('billing-lots.appeals.download');
    Route::get('pharmacy', [PharmacyController::class, 'index'])->middleware('throttle:120,1')->name('pharmacy.index');
    Route::get('pharmacy/prescriptions/{prescription}', [PharmacyController::class, 'show'])->middleware('throttle:120,1')->name('pharmacy.show');
    Route::post('pharmacy/items/{item}/review', [PharmacyController::class, 'review'])->middleware('throttle:120,1')->name('pharmacy.review');
    Route::post('pharmacy/items/{item}/dispense', [PharmacyController::class, 'dispense'])->middleware('throttle:120,1')->name('pharmacy.dispense');
    Route::post('pharmacy/dispensings/{dispensing}/return', [PharmacyController::class, 'returnItem'])->middleware('throttle:120,1')->name('pharmacy.return');
    // Step 4c.1a (ADR-0007): the unit's pharmacy stock.
    Route::get('stock', [StockController::class, 'index'])->middleware('throttle:120,1')->name('stock.index');
    Route::post('stock/entries', [StockController::class, 'storeEntry'])->middleware('throttle:60,1')->name('stock.entries.store');
    Route::post('stock/lots/{lot}/discards', [StockController::class, 'discard'])->middleware('throttle:60,1')->name('stock.discards.store');
    // Step 4c.4c (ADR-0007): the stock indicators; the network admin sees every unit.
    Route::get('stock/indicators', [StockIndicatorController::class, 'index'])->middleware('throttle:120,1')->name('stock.indicators.index');
    // Step 4c.4b (ADR-0007): the movements report and its CSV, for the pharmacy and the unit manager.
    Route::get('stock/movements', [StockReportController::class, 'index'])->middleware('throttle:120,1')->name('stock.movements.index');
    Route::get('stock/movements.csv', [StockReportController::class, 'csv'])->middleware('throttle:20,1')->name('stock.movements.csv');
    // Step 4c.3b (ADR-0007): the inventory, counted by the pharmacy and approved by another person, the unit manager.
    Route::get('stock/inventories', [StockInventoryController::class, 'index'])->middleware('throttle:120,1')->name('stock.inventories.index');
    Route::post('stock/inventories', [StockInventoryController::class, 'store'])->middleware('throttle:30,1')->name('stock.inventories.store');
    Route::get('stock/inventories/{inventory}', [StockInventoryController::class, 'show'])->middleware('throttle:120,1')->name('stock.inventories.show');
    Route::put('stock/inventories/{inventory}/lines/{line}', [StockInventoryController::class, 'count'])->middleware('throttle:240,1')->name('stock.inventories.count');
    Route::post('stock/inventories/{inventory}/found', [StockInventoryController::class, 'found'])->middleware('throttle:60,1')->name('stock.inventories.found');
    Route::post('stock/inventories/{inventory}/submit', [StockInventoryController::class, 'submit'])->middleware('throttle:30,1')->name('stock.inventories.submit');
    Route::post('stock/inventories/{inventory}/approve', [StockInventoryController::class, 'approve'])->middleware('throttle:30,1')->name('stock.inventories.approve');
    Route::post('stock/inventories/{inventory}/refuse', [StockInventoryController::class, 'refuse'])->middleware('throttle:30,1')->name('stock.inventories.refuse');
    Route::put('stock/minimums', [StockController::class, 'updateMinimum'])->middleware('throttle:60,1')->name('stock.minimums.update');
    Route::get('emergency', [EmergencyController::class, 'index'])->name('emergency.index');
    Route::post('units/{unit}/emergency-arrivals', [EmergencyController::class, 'arrive'])->middleware('throttle:60,1')->name('emergency.arrive');
    Route::post('emergency-episodes/{episode}/triage', [EmergencyController::class, 'triage'])->middleware('throttle:60,1')->name('emergency.triage');
    Route::post('emergency-episodes/{episode}/call', [EmergencyController::class, 'call'])->middleware('throttle:60,1')->name('emergency.call');
    Route::post('emergency-episodes/{episode}/evade', [EmergencyController::class, 'evade'])->middleware('throttle:60,1')->name('emergency.evade');
    Route::get('triage-levels', [TriageLevelController::class, 'index'])->name('triage-levels.index');
    Route::put('triage-levels/{triageLevel}', [TriageLevelController::class, 'update'])->name('triage-levels.update');
    Route::get('ward', [WardController::class, 'index'])->name('ward.index');
    Route::get('ward/{admission}', [WardController::class, 'show'])->middleware('throttle:120,1')->name('ward.show');
    Route::post('ward/{admission}/documents', [ClinicalDocumentController::class, 'storeForAdmission'])->middleware('throttle:30,1')->name('ward.documents.store');
    Route::post('ward/{admission}/discharge', [WardController::class, 'discharge'])->middleware('throttle:30,1')->name('ward.discharge');
    Route::post('ward/{admission}/hand-over', [WardController::class, 'handOver'])->middleware('throttle:30,1')->name('ward.hand-over');
    Route::post('ward/{admission}/notes', [WardController::class, 'storeNote'])->middleware('throttle:60,1')->name('ward.notes.store');
    // Step 6b.5a: the AIH request report of a SUS admission and the SIGTAP search of its procedure field.
    Route::post('ward/{admission}/aih-request', [AihRequestController::class, 'store'])->middleware('throttle:30,1')->name('ward.aih-request.store');
    // Step 6b.5a2: the reports for the auditor and for the doctor who signed them, also after the discharge.
    Route::get('aih-requests', [AihRequestController::class, 'index'])->middleware('throttle:60,1')->name('aih-requests.index');
    Route::get('aih-requests/{report}/pdf', [AihRequestController::class, 'pdf'])->middleware('throttle:30,1')->name('aih-requests.pdf');
    Route::get('sigtap-procedures', [AihRequestController::class, 'search'])->middleware('throttle:60,1')->name('sigtap.procedures');
    Route::get('admissions', [AdmissionController::class, 'index'])->name('admissions.index');
    Route::post('admissions/{admission}/transfers', [TransferController::class, 'store'])->middleware('throttle:30,1')->name('transfers.store');
    Route::post('transfers/{transfer}/accept', [TransferController::class, 'accept'])->middleware('throttle:60,1')->name('transfers.accept');
    Route::post('transfers/{transfer}/refuse', [TransferController::class, 'refuse'])->middleware('throttle:60,1')->name('transfers.refuse');
    Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->middleware('throttle:60,1')->name('transfers.cancel');
    Route::post('transfers/{transfer}/depart', [TransferController::class, 'depart'])->middleware('throttle:60,1')->name('transfers.depart');
    Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->middleware('throttle:60,1')->name('transfers.receive');
    Route::post('admissions/{admission}/move', [AdmissionController::class, 'move'])->middleware('throttle:60,1')->name('admissions.move');
    Route::post('admissions/{admission}/allocate', [AdmissionController::class, 'allocate'])->middleware('throttle:60,1')->name('admissions.allocate');
    Route::get('beds', [BedController::class, 'index'])->name('beds.index');
    Route::get('census', [CensusController::class, 'index'])->name('census.index');
    Route::post('units/{unit}/beds', [BedController::class, 'store'])->middleware('throttle:60,1')->name('beds.store');
    Route::post('beds/{bed}/deactivate', [BedController::class, 'deactivate'])->middleware('throttle:60,1')->name('beds.deactivate');
    Route::post('beds/{bed}/block', [BedController::class, 'block'])->middleware('throttle:60,1')->name('beds.block');
    Route::post('beds/{bed}/unblock', [BedController::class, 'unblock'])->middleware('throttle:60,1')->name('beds.unblock');
    Route::post('beds/{bed}/send-to-cleaning', [BedController::class, 'sendToCleaning'])->middleware('throttle:60,1')->name('beds.send-to-cleaning');
    Route::post('beds/{bed}/clean', [BedController::class, 'clean'])->middleware('throttle:60,1')->name('beds.clean');
    Route::get('visits', [VisitController::class, 'index'])->name('visits.index');
    Route::post('appointments/{appointment}/visit', [VisitController::class, 'open'])->middleware('throttle:60,1')->name('visits.open');
    Route::get('visits/{visit}', [VisitController::class, 'show'])->middleware('throttle:120,1')->name('visits.show');
    Route::post('visits/{visit}/close', [VisitController::class, 'close'])->middleware('throttle:60,1')->name('visits.close');
    Route::post('visits/{visit}/notes', [VisitController::class, 'storeNote'])->middleware('throttle:60,1')->name('visits.notes.store');
    Route::put('clinical-notes/{note}', [VisitController::class, 'updateNote'])->middleware('throttle:60,1')->name('clinical-notes.update');
    Route::post('clinical-notes/{note}/sign', [VisitController::class, 'signNote'])->middleware('throttle:60,1')->name('clinical-notes.sign');
    Route::get('appointments/queue', [AppointmentController::class, 'queue'])->name('appointments.queue');
    Route::post('appointments/{appointment}/arrive', [AppointmentController::class, 'arrive'])->middleware('throttle:60,1')->name('appointments.arrive');
    Route::post('appointments/{appointment}/no-show', [AppointmentController::class, 'noShow'])->middleware('throttle:60,1')->name('appointments.no-show');
    Route::get('patient-merges', [PatientMergeController::class, 'index'])->name('patient-merges.index');
    Route::post('patient-merges', [PatientMergeController::class, 'store'])->middleware('throttle:30,1')->name('patient-merges.store');
    Route::post('patient-merges/{merge}/undo', [PatientMergeController::class, 'undo'])->middleware('throttle:30,1')->name('patient-merges.undo');
    Route::post('patients/search', [PatientController::class, 'search'])->middleware('throttle:30,1')->name('patients.search');
    Route::get('patients/search', fn () => to_route('patients.index'));
    Route::resource('patients', PatientController::class)->except('destroy')->middlewareFor(['store', 'update'], 'throttle:30,1');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/schedule.{format}', [ReportController::class, 'schedule'])->whereIn('format', ['csv', 'pdf'])->middleware('throttle:20,1')->name('reports.schedule');
    Route::get('reports/attendance.{format}', [ReportController::class, 'attendance'])->whereIn('format', ['csv', 'pdf'])->middleware('throttle:20,1')->name('reports.attendance');
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
    // Step 5.2: exam orders of an open visit or an active admission.
    Route::get('admissions/{admission}/exams', [ExamOrderController::class, 'forAdmission'])->middleware('throttle:120,1')->name('exam-orders.admission');
    Route::post('admissions/{admission}/exams', [ExamOrderController::class, 'storeForAdmission'])->middleware('throttle:30,1')->name('exam-orders.admission.store');
    Route::get('visits/{visit}/exams', [ExamOrderController::class, 'forVisit'])->middleware('throttle:120,1')->name('exam-orders.visit');
    Route::post('visits/{visit}/exams', [ExamOrderController::class, 'storeForVisit'])->middleware('throttle:30,1')->name('exam-orders.visit.store');
    Route::post('exam-orders/{order}/cancel', [ExamOrderController::class, 'cancel'])->middleware('throttle:30,1')->name('exam-orders.cancel');
    // Step 5.3 (ADR-0008): the queues of the Laboratory and the Imaging, and the collection or performance.
    Route::get('exam-queue', [ExamQueueController::class, 'index'])->middleware('throttle:120,1')->name('exam-queue.index');
    Route::get('exam-queue/orders/{order}', [ExamQueueController::class, 'show'])->middleware('throttle:120,1')->name('exam-queue.show');
    // Step 5.3b: orders without collection for 7 days, for the unit manager.
    Route::get('exam-overdue', [ExamOverdueController::class, 'index'])->middleware('throttle:120,1')->name('exam-overdue.index');
    // Step 5b.3c: stalled surgeries for the unit manager.
    Route::get('surgery-overdue', [SurgeryOverdueController::class, 'index'])->middleware('throttle:120,1')->name('surgery-overdue.index');
    Route::post('exam-queue/items/{item}/collect', [ExamQueueController::class, 'collect'])->middleware('throttle:120,1')->name('exam-queue.collect');
    // Step 5.4: results released and rectified by the exam team; the PDF for whoever reads the record.
    Route::post('exam-queue/items/{item}/result', [ExamResultController::class, 'release'])->middleware('throttle:30,1')->name('exam-results.release');
    Route::post('exam-results/{result}/acknowledge', [ExamResultController::class, 'acknowledge'])->middleware('throttle:60,1')->name('exam-results.acknowledge');
    Route::get('exam-results/{result}/attachment', [ExamResultController::class, 'download'])->middleware('throttle:60,1')->name('exam-results.download');
    // Step 5b.2: surgery orders of an open visit or an active admission.
    Route::get('admissions/{admission}/surgeries', [SurgeryOrderController::class, 'forAdmission'])->middleware('throttle:120,1')->name('surgery-orders.admission');
    Route::post('admissions/{admission}/surgeries', [SurgeryOrderController::class, 'storeForAdmission'])->middleware('throttle:30,1')->name('surgery-orders.admission.store');
    Route::get('visits/{visit}/surgeries', [SurgeryOrderController::class, 'forVisit'])->middleware('throttle:120,1')->name('surgery-orders.visit');
    Route::post('visits/{visit}/surgeries', [SurgeryOrderController::class, 'storeForVisit'])->middleware('throttle:30,1')->name('surgery-orders.visit.store');
    Route::post('surgeries/{surgery}/cancel', [SurgeryOrderController::class, 'cancel'])->middleware('throttle:30,1')->name('surgery-orders.cancel');
    // Step 5b.3b: the record for the surgery, by the surgical center and the scheduled team.
    Route::get('surgeries/mine', [SurgeryRecordController::class, 'mine'])->middleware('throttle:120,1')->name('surgery-record.mine');
    Route::get('surgeries/{surgery}/record', [SurgeryRecordController::class, 'show'])->middleware('throttle:120,1')->name('surgery-record.show');
    // Step 5b.4: the consent term and the WHO safe surgery checklist.
    Route::post('surgeries/{surgery}/consent', [SurgeryChecklistController::class, 'storeConsent'])->middleware('throttle:30,1')->name('surgery-checklist.consent');
    Route::post('surgeries/{surgery}/consent-waiver', [SurgeryChecklistController::class, 'waiveConsent'])->middleware('throttle:30,1')->name('surgery-checklist.waiver');
    Route::post('surgeries/{surgery}/checklist/{step}', [SurgeryChecklistController::class, 'confirm'])->middleware('throttle:60,1')->whereIn('step', ['sign_in', 'time_out', 'sign_out'])->name('surgery-checklist.confirm');
    // Step 5b.5: the surgical description and its addenda.
    Route::post('surgeries/{surgery}/description', [SurgeryDescriptionController::class, 'store'])->middleware('throttle:30,1')->name('surgery-description.store');
    Route::post('surgeries/{surgery}/description/addenda', [SurgeryDescriptionController::class, 'addendum'])->middleware('throttle:30,1')->name('surgery-description.addendum');
    // Step 5b.6c: the materials a surgery used, recorded and given back by the surgical center.
    Route::post('surgeries/{surgery}/materials', [SurgeryMaterialController::class, 'store'])->middleware('throttle:60,1')->name('surgery-materials.store');
    Route::post('surgeries/{surgery}/materials/{lot}/return', [SurgeryMaterialController::class, 'giveBack'])->middleware('throttle:60,1')->name('surgery-materials.return');
    Route::get('surgery-consents/{consent}/download', [SurgeryChecklistController::class, 'downloadConsent'])->middleware('throttle:60,1')->name('surgery-checklist.consent.download');
    // Step 5b.3a: the surgical agenda of the Surgical center role.
    Route::get('surgery-schedule', [SurgeryScheduleController::class, 'index'])->middleware('throttle:120,1')->name('surgery-schedule.index');
    Route::get('surgery-schedule/{surgery}', [SurgeryScheduleController::class, 'show'])->middleware('throttle:120,1')->name('surgery-schedule.show');
    Route::post('surgery-schedule/{surgery}/schedule', [SurgeryScheduleController::class, 'schedule'])->middleware('throttle:30,1')->name('surgery-schedule.schedule');
    Route::post('surgery-schedule/{surgery}/reschedule', [SurgeryScheduleController::class, 'reschedule'])->middleware('throttle:30,1')->name('surgery-schedule.reschedule');
    Route::post('surgery-schedule/{surgery}/team', [SurgeryScheduleController::class, 'addMember'])->middleware('throttle:60,1')->name('surgery-schedule.team.store');
    Route::post('surgery-team/{member}/remove', [SurgeryScheduleController::class, 'removeMember'])->middleware('throttle:60,1')->name('surgery-schedule.team.remove');
    Route::post('surgery-schedule/{surgery}/cancel', [SurgeryScheduleController::class, 'cancel'])->middleware('throttle:30,1')->name('surgery-schedule.cancel');
    // Step 5.1a: the network exam catalog.
    Route::get('exams', [ExamController::class, 'index'])->name('exams.index');
    Route::post('exams', [ExamController::class, 'store'])->middleware('throttle:60,1')->name('exams.store');
    Route::put('exams/{exam}', [ExamController::class, 'update'])->middleware('throttle:60,1')->name('exams.update');
    Route::put('exam-targets', [ExamController::class, 'targets'])->middleware('throttle:60,1')->name('exams.targets');
    // Step 5b.1a: the network surgical procedures and the operating rooms of each unit.
    Route::get('surgery-catalog', [SurgeryCatalogController::class, 'index'])->name('surgery-catalog.index');
    Route::post('surgical-procedures', [SurgeryCatalogController::class, 'storeProcedure'])->middleware('throttle:60,1')->name('surgical-procedures.store');
    Route::put('surgical-procedures/{procedure}', [SurgeryCatalogController::class, 'updateProcedure'])->middleware('throttle:60,1')->name('surgical-procedures.update');
    // Step 6.1a: the network's billable items, price tables and prices.
    Route::get('billing-prices', [BillingPriceController::class, 'index'])->name('billing-prices.index');
    Route::post('billing-items', [BillingPriceController::class, 'storeItem'])->middleware('throttle:60,1')->name('billing-items.store');
    Route::put('billing-items/{item}', [BillingPriceController::class, 'updateItem'])->middleware('throttle:60,1')->name('billing-items.update');
    Route::post('price-tables', [BillingPriceController::class, 'storeTable'])->middleware('throttle:60,1')->name('price-tables.store');
    Route::put('price-tables/{table}', [BillingPriceController::class, 'updateTable'])->middleware('throttle:60,1')->name('price-tables.update');
    Route::post('price-tables/{table}/prices', [BillingPriceController::class, 'storePrice'])->middleware('throttle:60,1')->name('price-tables.prices.store');
    // Step 6b.3: the official SIGTAP table of each competence (SUS).
    Route::get('sigtap', [SigtapController::class, 'index'])->name('sigtap.index');
    Route::post('sigtap', [SigtapController::class, 'store'])->middleware('throttle:10,1')->name('sigtap.store');
    // Step 6.1b: the insurers' contracts and the private table.
    Route::put('insurers/{insurer}/contract', [BillingPriceController::class, 'saveContract'])->middleware('throttle:60,1')->name('insurer-contracts.save');
    Route::put('insurer-contracts/{contract}/authorizations', [BillingPriceController::class, 'saveAuthorizations'])->middleware('throttle:60,1')->name('insurer-contracts.authorizations');
    Route::post('price-tables/{table}/private', [BillingPriceController::class, 'setPrivate'])->middleware('throttle:60,1')->name('price-tables.private');
    // Step 5b.6a: the network materials, on the same screen.
    Route::post('materials', [SurgeryCatalogController::class, 'storeMaterial'])->middleware('throttle:60,1')->name('materials.store');
    Route::put('materials/{material}', [SurgeryCatalogController::class, 'updateMaterial'])->middleware('throttle:60,1')->name('materials.update');
    Route::post('operating-rooms', [SurgeryCatalogController::class, 'storeRoom'])->middleware('throttle:60,1')->name('operating-rooms.store');
    Route::put('operating-rooms/{room}', [SurgeryCatalogController::class, 'updateRoom'])->middleware('throttle:60,1')->name('operating-rooms.update');
    Route::get('medications', [MedicationController::class, 'index'])->name('medications.index');
    Route::post('medications', [MedicationController::class, 'store'])->middleware('throttle:60,1')->name('medications.store');
    Route::put('medications/{medication}', [MedicationController::class, 'update'])->middleware('throttle:60,1')->name('medications.update');
    Route::post('medication-interactions', [MedicationController::class, 'storeInteraction'])->middleware('throttle:60,1')->name('medication-interactions.store');
    Route::put('medication-interactions/{interaction}', [MedicationController::class, 'updateInteraction'])->middleware('throttle:60,1')->name('medication-interactions.update');
    Route::get('insurers', [InsurerController::class, 'index'])->name('insurers.index');
    Route::post('insurers', [InsurerController::class, 'store'])->name('insurers.store');
    Route::put('insurers/{insurer}', [InsurerController::class, 'update'])->name('insurers.update');
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
