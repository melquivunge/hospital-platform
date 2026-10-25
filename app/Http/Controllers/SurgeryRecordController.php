<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\OperatingRoom;
use App\Models\Patient;
use App\Models\Surgery;
use App\Models\SurgeryChecklistStep;
use App\Models\SurgeryTeamMember;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The record for the surgery (step 5b.3b, ADR-0009). The Surgical center of the unit and the members of the active
 * team read the patient's record, with the screens of who attends, while the surgery is requested or scheduled;
 * each opening is a row of the trail with the reason "surgery". "Minhas cirurgias" lists the open surgeries where
 * the user is on the team, without a read.
 */
class SurgeryRecordController extends Controller
{
    public function mine(Request $request): Response
    {
        $professional = ClinicalAccess::professional($request->user());
        abort_if($professional === null, 403);
        $surgeries = Surgery::query()->whereIn('status', Surgery::LIVE)
            ->whereHas('team', fn ($team) => $team->where('professional_id', $professional->id))
            ->with(['team' => fn ($team) => $team->where('professional_id', $professional->id)])
            ->orderByRaw('scheduled_start IS NULL')->orderBy('scheduled_start')->orderBy('id')->limit(100)->get();
        $places = ExamQueueController::places($surgeries);
        $patients = Patient::query()->whereKey($surgeries->pluck('patient_id')->unique()->all())->get(['id', 'name', 'social_name'])->keyBy('id');
        $rooms = OperatingRoom::query()->whereKey($surgeries->pluck('operating_room_id')->filter()->unique()->all())->pluck('name', 'id');
        $timezones = Unit::query()->whereKey($surgeries->pluck('unit_id')->unique()->all())->pluck('timezone', 'id');

        return Inertia::render('surgery-record/mine', [
            'surgeries' => $surgeries->map(fn (Surgery $surgery) => [
                'id' => $surgery->id,
                'patient' => ($patient = $patients->get($surgery->patient_id)) === null ? null : ($patient->social_name ?? $patient->name),
                'place' => $places[$surgery->id] ?? null,
                'procedure' => $surgery->procedure_name,
                'laterality' => $surgery->laterality,
                'urgency' => $surgery->urgency,
                'room' => $rooms->get((int) $surgery->operating_room_id),
                'start' => $surgery->scheduled_start?->timezone($timezones->get($surgery->unit_id) ?? 'UTC')->format('d/m/Y H:i'),
                'role' => $surgery->team->first()?->role,
            ])->values(),
            'roles' => SurgeryTeamMember::ROLES,
            'lateralities' => Surgery::LATERALITIES,
            'urgencies' => Surgery::URGENCIES,
        ]);
    }

    public function show(Request $request, Surgery $surgery): Response
    {
        // Only while open, only for the unit's center or the active team (ADR-0009).
        abort_unless(ClinicalAccess::openSurgeries($request->user())->whereKey($surgery->id)->exists(), 403);
        $patient = Patient::query()->findOrFail($surgery->patient_id);
        ClinicalAccess::recordRead($request->user(), $patient, $surgery);
        Inertia::encryptHistory();
        $timezone = Unit::query()->whereKey($surgery->unit_id)->value('timezone') ?? 'UTC';
        $ids = ClinicalAccess::recordIds($patient);
        $surgery->load(['team.professional:id,name', 'room:id,name', 'checklist', 'consents']);
        $users = User::query()->whereKey([...$surgery->checklist->pluck('confirmed_by_user_id')->all(), ...$surgery->consents->pluck('created_by_user_id')->all()])->pluck('name', 'id');
        $consent = $surgery->consents->last();
        $author = User::query()->whereKey($surgery->author_user_id)->value('name');

        return Inertia::render('surgery-record/show', [
            'surgery' => [
                'id' => $surgery->id,
                'procedure' => $surgery->procedure_name,
                'laterality' => $surgery->laterality,
                'urgency' => $surgery->urgency,
                'indication' => $surgery->indication,
                'status' => $surgery->status,
                'desiredDate' => $surgery->desired_date->format('d/m/Y'),
                'author' => $author,
                'room' => $surgery->room?->name,
                'start' => $surgery->scheduled_start?->timezone($timezone)->format('d/m/Y H:i'),
                'end' => $surgery->scheduled_end?->timezone($timezone)->format('d/m/Y H:i'),
                'urgentReason' => $surgery->urgent_reason,
                'team' => $surgery->team->map(fn (SurgeryTeamMember $member) => ['id' => $member->id, 'name' => $member->professional->name, 'role' => $member->role])->values(),
                'place' => ExamQueueController::places([$surgery])[$surgery->id],
                'startedAt' => $surgery->started_at?->timezone($timezone)->format('d/m/Y H:i'),
                // Step 5b.4: the term that counts (the last one) and the checklist.
                'consent' => $consent === null ? null : [
                    'id' => $consent->id,
                    'kind' => $consent->kind,
                    'name' => $consent->original_name,
                    'waiverReason' => $consent->waiver_reason,
                    'by' => $users->get($consent->created_by_user_id),
                    'at' => $consent->created_at->timezone($timezone)->format('d/m/Y H:i'),
                ],
                'checklist' => $surgery->checklist->mapWithKeys(fn (SurgeryChecklistStep $step) => [$step->step => [
                    'answers' => $step->answers,
                    'by' => $users->get($step->confirmed_by_user_id),
                    'at' => $step->confirmed_at->timezone($timezone)->format('d/m/Y H:i'),
                ]]),
                'canConfirm' => SurgeryChecklistController::canConfirm($request->user(), $surgery),
                'canManage' => in_array($surgery->unit_id, SurgeryScheduleController::unitIds(UnitPolicy::currentMembership($request->user())), true),
            ],
            'roles' => SurgeryTeamMember::ROLES,
            'steps' => SurgeryChecklistStep::STEPS,
            'answers' => SurgeryChecklistStep::ANSWERS,
            'lateralities' => Surgery::LATERALITIES,
            'urgencies' => Surgery::URGENCIES,
            'patient' => VisitController::patientHeader($patient),
            'allergies' => PatientAllergyController::listing($ids, $timezone),
            'record' => VisitController::signedRecord($ids, $timezone),
            'prescriptions' => ExamQueueController::readOnlyPrescriptions($request->user(), $surgery, $timezone),
            // Read-only here: withdrawing belongs to who attends.
            'documents' => array_map(fn (array $document) => [...$document, 'canWithdraw' => false], ClinicalDocumentController::listing($ids, $timezone, $request->user()->id)),
            'examResults' => ExamResultController::listing($ids, $timezone, $request->user()),
        ]);
    }
}
