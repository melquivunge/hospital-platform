<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionReview;
use App\Models\RoleAssignment;
use App\Models\Unit;
use App\Models\Visit;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pharmacy review (step 4b.3a, ADR-0005). The unit's Pharmacy role sees a queue of the valid prescriptions of
 * the unit (identification and counts only, no read logged) and opens one at a time: only the prescription,
 * the allergies and the patient's identification, each opening logged with reason "pharmacy". Each item is
 * approved, approved with a note or refused with a reason, once; a refusal notifies the prescriber.
 */
class PharmacyController extends Controller
{
    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(self::unitIds(UnitPolicy::currentMembership($request->user())))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', (int) $request->query('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';

        $queue = self::valid()->where(fn (Builder $query) => $query
            ->whereIn('admission_id', Admission::query()->where('unit_id', $unit->id)->whereIn('status', Admission::ACTIVE)->select('id'))
            ->orWhereIn('visit_id', Visit::query()->where('unit_id', $unit->id)->whereNull('closed_at')->select('id')))
            ->withCount(['items as pending' => fn (Builder $items) => $items->whereNull('suspended_at')->whereDoesntHave('review')])
            ->with(['admission.patient:id,name,social_name', 'admission.bed:id,code,room,sector_id', 'admission.bed.sector:id,name', 'visit.patient:id,name,social_name'])
            ->orderByDesc('pending')->orderBy('signed_at')->get();

        return Inertia::render('pharmacy/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'prescriptions' => $queue->map(fn (Prescription $prescription) => [
                'id' => $prescription->id,
                'patient' => ($patient = ($prescription->admission ?? $prescription->visit)?->patient) === null ? null : ($patient->social_name ?? $patient->name),
                'place' => $prescription->admission !== null ? (WardController::bedLabel($prescription->admission) ?? 'Aguardando leito') : 'Urgência',
                'signedAt' => $prescription->signed_at?->setTimezone($timezone)->format('d/m H:i'),
                'pending' => (int) $prescription->getAttribute('pending'),
            ])->values(),
        ]);
    }

    public function show(Request $request, Prescription $prescription): Response
    {
        [$encounter, $patient] = self::access($request, $prescription);
        ClinicalAccess::recordRead($request->user(), $patient, $prescription);
        Inertia::encryptHistory();
        $timezone = Unit::query()->whereKey($encounter->unit_id)->value('timezone') ?? 'UTC';
        $prescription->load(['items.review', 'professional:id,name']);

        return Inertia::render('pharmacy/show', [
            'prescription' => [
                'id' => $prescription->id,
                'place' => $encounter instanceof Admission ? (WardController::bedLabel($encounter->load('bed.sector')) ?? 'Aguardando leito') : 'Urgência',
                'signedAt' => $prescription->signed_at?->setTimezone($timezone)->format('d/m/Y H:i'),
                'validUntil' => $prescription->valid_until?->setTimezone($timezone)->format('d/m/Y H:i'),
                'author' => $prescription->professional?->name,
                'unitId' => $encounter->unit_id,
            ],
            'patient' => VisitController::patientHeader($patient),
            'allergies' => PatientAllergyController::listing(ClinicalAccess::recordIds($patient), $timezone),
            'items' => array_map(fn (PrescriptionItem $item) => PrescriptionController::describe($item, $timezone), $prescription->items->all()),
            'decisions' => PrescriptionReview::DECISIONS,
        ]);
    }

    public function review(Request $request, PrescriptionItem $item): RedirectResponse
    {
        $prescription = Prescription::query()->findOrFail($item->prescription_id);
        [$encounter] = self::access($request, $prescription);
        $bag = "review{$item->id}";
        $data = $request->validateWithBag($bag, [
            'decision' => ['required', Rule::in(array_keys(PrescriptionReview::DECISIONS))],
            'note' => ['exclude_if:decision,approved', 'required', 'string', 'max:500'],
        ], ['note.required' => 'Escreva a observação ou o motivo da recusa.'], ['decision' => 'decisão', 'note' => 'observação']);

        try {
            DB::transaction(function () use ($item, $request, $data, $prescription, $encounter, $bag) {
                // The same item lock as the suspension: a suspended item is not reviewed.
                $locked = PrescriptionItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                if ($locked->suspended_at !== null) {
                    throw ValidationException::withMessages(['decision' => 'Este item foi suspenso pelo médico.'])->errorBag($bag);
                }
                $review = PrescriptionReview::query()->create([
                    'prescription_item_id' => $locked->id, 'decision' => $data['decision'], 'note' => $data['note'] ?? null,
                    'reviewed_by_user_id' => $request->user()->id, 'created_at' => now(),
                ]);
                AuditEvent::record('prescription_item.reviewed', $review->tenant_id, $locked, [
                    'decision' => $review->decision, 'prescription_id' => $prescription->id, 'author_user_id' => $prescription->author_user_id,
                    ...($encounter instanceof Admission ? ['admission_id' => $encounter->id] : ['visit_id' => $encounter->id]),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['decision' => 'Este item já foi avaliado.'])->errorBag($bag);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['decision'] === 'refused' ? 'Item recusado. O médico foi avisado.' : 'Avaliação registrada.']);

        return back();
    }

    /**
     * The units where the membership has the Pharmacy role.
     *
     * @return list<int>
     */
    public static function unitIds(?Membership $membership): array
    {
        if ($membership === null || $membership->revoked_at !== null) {
            return [];
        }

        return array_values(array_unique($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => $assignment->role === Role::Pharmacy)
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)->all()));
    }

    /**
     * Signed, still valid and the newest of its encounter.
     *
     * @return Builder<Prescription>
     */
    private static function valid(): Builder
    {
        return Prescription::query()->where('status', 'signed')->where('valid_until', '>', now())
            ->whereNotExists(fn ($newer) => $newer->from('prescriptions as newer')->where('newer.status', 'signed')
                ->where(fn ($same) => $same->whereColumn('newer.admission_id', 'prescriptions.admission_id')->orWhereColumn('newer.visit_id', 'prescriptions.visit_id'))
                ->where(fn ($later) => $later->whereColumn('newer.signed_at', '>', 'prescriptions.signed_at')
                    ->orWhere(fn ($tie) => $tie->whereColumn('newer.signed_at', 'prescriptions.signed_at')->whereColumn('newer.id', '>', 'prescriptions.id'))));
    }

    /**
     * The open encounter and the patient, if the prescription is the valid one and the user has the Pharmacy role in
     * the encounter's unit (ADR-0005); otherwise 403.
     *
     * @return array{Admission|Visit, Patient}
     */
    private static function access(Request $request, Prescription $prescription): array
    {
        abort_unless(self::valid()->whereKey($prescription->id)->exists(), 403);
        $encounter = $prescription->admission_id !== null
            ? Admission::query()->whereKey($prescription->admission_id)->whereIn('status', Admission::ACTIVE)->first()
            : Visit::query()->whereKey($prescription->visit_id)->whereNull('closed_at')->first();
        abort_if($encounter === null, 403);
        abort_unless(in_array((int) $encounter->unit_id, self::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);

        return [$encounter, Patient::query()->findOrFail($encounter->patient_id)];
    }
}
