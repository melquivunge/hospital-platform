<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\OperatingRoom;
use App\Models\Patient;
use App\Models\Surgery;
use App\Models\SurgeryChecklistStep;
use App\Models\SurgeryDescription;
use App\Models\SurgeryTeamMember;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use Illuminate\Database\Eloquent\Builder;
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
        $surgeries = self::mineQuery($professional->id)
            ->with(['team' => fn ($team) => $team->where('professional_id', $professional->id)])
            ->orderByRaw("status = 'completed'")->orderByRaw('scheduled_start IS NULL')->orderBy('scheduled_start')->orderBy('id')->limit(100)->get();
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
                'status' => $surgery->status,
            ])->values(),
            'roles' => SurgeryTeamMember::ROLES,
            'lateralities' => Surgery::LATERALITIES,
            'urgencies' => Surgery::URGENCIES,
            'addendumDays' => SurgeryDescription::ADDENDUM_DAYS,
        ]);
    }

    /**
     * The live surgeries where the professional is on the team, and, for ADDENDUM_DAYS, the completed ones where
     * they are a surgeon (step 5b.5).
     *
     * @return Builder<Surgery>
     */
    public static function mineQuery(int $professionalId): Builder
    {
        return Surgery::query()->where(fn (Builder $mine) => $mine
            // Security review 5b.7: only the ones whose record opens now, or still to be described by this surgeon, so the
            // link never leads to a 403.
            ->where(fn (Builder $live) => ClinicalAccess::qualifiedInSurgeryUnit($live->where(fn (Builder $open) => Surgery::recordWindow($open)
                ->orWhere(fn (Builder $started) => $started->where('status', 'in_progress')->whereHas('team', fn ($team) => $team->where('professional_id', $professionalId)->where('role', 'surgeon'))))
                ->whereHas('team', fn ($team) => $team->where('professional_id', $professionalId)), $professionalId))
            // Review 5b.5: only where the addendum is still allowed, so the link never leads to a 403.
            ->orWhere(fn (Builder $done) => ClinicalAccess::qualifiedInSurgeryUnit($done->where('status', 'completed')->where('completed_at', '>=', now()->subDays(SurgeryDescription::ADDENDUM_DAYS))
                ->whereHas('team', fn ($team) => $team->where('professional_id', $professionalId)->where('role', 'surgeon')), $professionalId)));
    }

    public function show(Request $request, Surgery $surgery): Response
    {
        // The record only while open, only for the unit's center or the active team (ADR-0009); after the
        // completion, the surgeons of the team see the surgery and its description, without the record, to sign
        // addenda (step 5b.5).
        $open = ClinicalAccess::openSurgeries($request->user())->whereKey($surgery->id)->exists();
        $canAddendum = SurgeryDescriptionController::canAddendum($request->user(), $surgery);
        // A surgery left in progress past its window still gets its description: its surgeons keep the restricted view.
        $toDescribe = $surgery->status === 'in_progress' && SurgeryDescriptionController::canSign($request->user(), $surgery);
        abort_unless($open || $canAddendum || $toDescribe, 403);
        $patient = Patient::query()->findOrFail($surgery->patient_id);
        ClinicalAccess::recordRead($request->user(), $patient, $surgery);
        Inertia::encryptHistory();
        $timezone = Unit::query()->whereKey($surgery->unit_id)->value('timezone') ?? 'UTC';
        $ids = ClinicalAccess::recordIds($patient);
        $surgery->load(['team.professional:id,name', 'room:id,name', 'checklist', 'consents', 'description.addenda']);
        $canSign = SurgeryDescriptionController::canSign($request->user(), $surgery);
        $users = User::query()->whereKey([...$surgery->checklist->pluck('confirmed_by_user_id')->all(), ...$surgery->consents->pluck('created_by_user_id')->all()])->pluck('name', 'id');
        $consent = $surgery->consents->last();
        $author = User::query()->whereKey($surgery->author_user_id)->value('name');

        return Inertia::render('surgery-record/show', [
            'surgery' => [
                'id' => $surgery->id,
                'procedure' => $surgery->procedure_name,
                'laterality' => $surgery->laterality,
                'urgency' => $surgery->urgency,
                // Review 5b.5: the restricted view keeps the surgery's own records, not the clinical indication.
                'indication' => $open ? $surgery->indication : null,
                'status' => $surgery->status,
                'desiredDate' => $surgery->desired_date->format('d/m/Y'),
                'author' => $author,
                'room' => $surgery->room?->name,
                'start' => $surgery->scheduled_start?->timezone($timezone)->format('d/m/Y H:i'),
                'end' => $surgery->scheduled_end?->timezone($timezone)->format('d/m/Y H:i'),
                // Free texts that may be clinical: only while the record is open (security review 5b.7).
                'urgentReason' => $open ? $surgery->urgent_reason : null,
                'team' => $surgery->team->map(fn (SurgeryTeamMember $member) => ['id' => $member->id, 'name' => $member->professional->name, 'role' => $member->role])->values(),
                'place' => ExamQueueController::places([$surgery])[$surgery->id],
                'startedAt' => $surgery->started_at?->timezone($timezone)->format('d/m/Y H:i'),
                // Step 5b.4: the term that counts (the last one) and the checklist.
                'consent' => $consent === null ? null : [
                    'id' => $consent->id,
                    'kind' => $consent->kind,
                    'name' => $consent->original_name,
                    'waiverReason' => $open ? $consent->waiver_reason : null,
                    'by' => $users->get($consent->created_by_user_id),
                    'at' => $consent->created_at->timezone($timezone)->format('d/m/Y H:i'),
                ],
                'checklist' => $surgery->checklist->mapWithKeys(fn (SurgeryChecklistStep $step) => [$step->step => [
                    'answers' => $step->answers,
                    'by' => $users->get($step->confirmed_by_user_id),
                    'at' => $step->confirmed_at->timezone($timezone)->format('d/m/Y H:i'),
                ]]),
                'canConfirm' => $open && SurgeryChecklistController::canConfirm($request->user(), $surgery),
                'completedAt' => $surgery->completed_at?->timezone($timezone)->format('d/m/Y H:i'),
                // Step 5b.5: the signed description, and what the user may sign now.
                'description' => $surgery->description === null ? null : SurgeryDescriptionController::present($surgery, $timezone),
                'canDescribe' => $canSign && $surgery->status === 'in_progress' && $surgery->checklist->contains('step', 'sign_out'),
                'canAddendum' => $canAddendum,
                'canManage' => in_array($surgery->unit_id, SurgeryScheduleController::unitIds(UnitPolicy::currentMembership($request->user())), true),
            ],
            'roles' => SurgeryTeamMember::ROLES,
            'steps' => SurgeryChecklistStep::STEPS,
            'answers' => SurgeryChecklistStep::ANSWERS,
            'lateralities' => Surgery::LATERALITIES,
            'urgencies' => Surgery::URGENCIES,
            'patient' => VisitController::patientHeader($patient),
            'open' => $open,
            'addendumDays' => SurgeryDescription::ADDENDUM_DAYS,
            'allergies' => $open ? PatientAllergyController::listing($ids, $timezone) : [],
            'record' => $open ? VisitController::signedRecord($ids, $timezone) : [],
            'prescriptions' => $open ? ExamQueueController::readOnlyPrescriptions($request->user(), $surgery, $timezone) : null,
            // Read-only here: withdrawing belongs to who attends.
            'documents' => $open ? array_map(fn (array $document) => [...$document, 'canWithdraw' => false], ClinicalDocumentController::listing($ids, $timezone, $request->user()->id)) : [],
            'examResults' => $open ? ExamResultController::listing($ids, $timezone, $request->user()) : [],
            'surgeryDescriptions' => $open ? SurgeryDescriptionController::listing($ids, $timezone) : [],
        ]);
    }
}
