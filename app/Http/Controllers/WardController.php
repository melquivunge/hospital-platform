<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Professionals\QualificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inpatients (step 4.4b, ADR-0004): the responsible doctor and the team of the bed's sector read and
 * write the record while the patient is there; access is evaluated at every request and every read goes to
 * the trail with the reason 'admission'. Notes, addenda and signing are those of delivery 3.
 */
class WardController extends Controller
{
    public function index(Request $request): Response
    {
        $professional = self::professional($request);
        Inertia::encryptHistory();
        $unitIds = Qualification::query()->where('professional_id', $professional->id)->where('status', QualificationStatus::Approved)->distinct()->pluck('unit_id');
        $sectorIds = $unitIds->flatMap(fn (int $unitId) => ClinicalAccess::wardSectorIds($professional, $unitId))->all();
        $bedIds = Bed::query()->whereIn('sector_id', $sectorIds)->pluck('id')->all();

        $admissions = Admission::query()->whereIn('status', Admission::ACTIVE)
            ->where(fn (Builder $query) => $query->where('responsible_professional_id', $professional->id)
                ->orWhere(fn (Builder $ward) => $ward->where('status', 'admitted')->whereIn('bed_id', $bedIds)))
            ->with(['patient:id,name,social_name', 'unit:id,name,timezone', 'responsible:id,name', 'bed:id,code,room,sector_id', 'bed.sector:id,name'])
            ->orderBy('admitted_at')->get();

        return Inertia::render('ward/index', [
            'admissions' => $admissions->map(fn (Admission $admission) => [
                'id' => $admission->id,
                'patient' => $admission->patient->social_name ?? $admission->patient->name ?? null,
                'unit' => $admission->unit?->name,
                'bed' => self::bedLabel($admission),
                'status' => $admission->status,
                'responsible' => $admission->responsible?->name,
                'mine' => $admission->responsible_professional_id === $professional->id,
            ])->values(),
        ]);
    }

    public function show(Request $request, Admission $admission): Response
    {
        self::professional($request);
        $patient = Patient::query()->findOrFail($admission->patient_id);
        abort_unless(ClinicalAccess::activeAdmission($request->user(), $patient)?->id === $admission->id, 403);
        ClinicalAccess::recordRead($request->user(), $patient, $admission);
        Inertia::encryptHistory();
        $timezone = $admission->unit()->value('timezone') ?? 'UTC';
        $ids = ClinicalAccess::recordIds($patient);
        $admission->load(['responsible:id,name', 'bed:id,code,room,sector_id', 'bed.sector:id,name']);

        return Inertia::render('ward/show', [
            'admission' => [
                'id' => $admission->id,
                'status' => $admission->status,
                'bed' => self::bedLabel($admission),
                'since' => ($admission->admitted_at ?? $admission->requested_at)->setTimezone($timezone)->format('d/m/Y H:i'),
                'responsible' => $admission->responsible?->name,
            ],
            'patient' => VisitController::patientHeader($patient),
            'record' => VisitController::signedRecord($ids, $timezone),
            'documents' => ClinicalDocumentController::listing($ids, $timezone, $request->user()->id),
            // Drafts are seen only by their author: the admission note and addenda to the record.
            'drafts' => ClinicalNote::query()->where('author_user_id', $request->user()->id)->where('status', 'draft')
                ->where(fn (Builder $query) => $query->where('admission_id', $admission->id)
                    ->orWhere(fn (Builder $addenda) => $addenda->whereNotNull('addendum_of_id')->whereIn('patient_id', $ids)))
                ->orderBy('id')->get(['id', 'body', 'addendum_of_id'])
                ->map(fn (ClinicalNote $note) => ['id' => $note->id, 'body' => $note->body, 'addendumOf' => $note->addendum_of_id]),
        ]);
    }

    /**
     * A new draft: the author's note of this admission (one at a time), or an addendum to the record.
     */
    public function storeNote(Request $request, Admission $admission): RedirectResponse
    {
        $professional = self::professional($request);
        $patient = Patient::query()->findOrFail($admission->patient_id);
        abort_unless(ClinicalAccess::activeAdmission($request->user(), $patient)?->id === $admission->id, 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:20000'], 'addendum_of_id' => ['nullable', 'integer']], [], ['body' => 'texto']);
        $original = VisitController::addendumOf($data['addendum_of_id'] ?? null, $patient);

        DB::transaction(function () use ($admission, $data, $request, $professional, $original, $patient) {
            // Locked: a draft never lands on an admission that ended in the meantime (discharge, step 4.7), and
            // the access is checked again under the lock (a bed or sector change in between).
            if (! in_array(Admission::query()->whereKey($admission->id)->lockForUpdate()->value('status'), Admission::ACTIVE, true)) {
                throw ValidationException::withMessages(['body' => 'Esta internação foi encerrada.']);
            }
            abort_unless(ClinicalAccess::activeAdmission($request->user(), $patient)?->id === $admission->id, 403);
            if ($original === null && ClinicalNote::query()->where('admission_id', $admission->id)->where('author_user_id', $request->user()->id)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['body' => 'Você já tem um rascunho de evolução nesta internação. Continue nele.']);
            }
            $note = ClinicalNote::query()->create([
                'patient_id' => $original->patient_id ?? $admission->patient_id, 'admission_id' => $original === null ? $admission->id : null,
                'addendum_of_id' => $original?->id, 'author_user_id' => $request->user()->id, 'author_professional_id' => $professional->id, 'body' => $data['body'],
            ]);
            AuditEvent::record('clinical_note.created', $note->tenant_id, $note, array_filter(['admission_id' => $admission->id, 'addendum_of' => $original?->id]));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rascunho salvo.']);

        return back();
    }

    private static function bedLabel(Admission $admission): ?string
    {
        return $admission->bed === null ? null : "{$admission->bed->sector?->name} · quarto {$admission->bed->room} · leito {$admission->bed->code}";
    }

    private static function professional(Request $request): Professional
    {
        return ClinicalAccess::professional($request->user()) ?? abort(403);
    }
}
