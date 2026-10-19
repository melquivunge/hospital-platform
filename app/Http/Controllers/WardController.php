<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            'history' => self::history($admission, $timezone),
            // Step 4.5: only the responsible doctor hands the admission over.
            'handOver' => $admission->responsible_professional_id === ClinicalAccess::professional($request->user())?->id
                ? array_values(array_filter(AdmissionController::requestOptions($admission->unit_id)['professionals'], fn (array $option) => $option['id'] !== $admission->responsible_professional_id))
                : null,
            // Step 4.6: the open transfer, and where the responsible doctor can send the patient.
            'transfer' => ($open = AdmissionTransfer::query()->where('admission_id', $admission->id)->whereIn('status', AdmissionTransfer::OPEN)->with('toUnit:id,name')->first()) === null
                ? null : ['id' => $open->id, 'status' => $open->status, 'to' => $open->toUnit?->name, 'mine' => $open->requested_by_user_id === $request->user()->id],
            'transferOptions' => $open === null && $admission->status === 'admitted' && $admission->responsible_professional_id === ClinicalAccess::professional($request->user())?->id
                ? array_values(Unit::query()->whereKeyNot($admission->unit_id)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Unit $unit) => ['id' => $unit->id, 'name' => $unit->name, ...AdmissionController::requestOptions($unit->id)])
                    ->filter(fn (array $unit) => $unit['sectors'] !== [] && $unit['professionals'] !== [])->all())
                : null,
            // Step 4.7: the responsible doctor discharges an admitted patient with no transfer open.
            'discharge' => $open === null && $admission->status === 'admitted' && $admission->responsible_professional_id === ClinicalAccess::professional($request->user())?->id
                ? [
                    'types' => array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label, 'summary' => in_array($value, Admission::SUMMARY_REQUIRED, true)], array_keys(Admission::DISCHARGE_TYPES), Admission::DISCHARGE_TYPES),
                    'otherDrafts' => ClinicalNote::query()->where('admission_id', $admission->id)->where('status', 'draft')->where('author_user_id', '<>', $request->user()->id)->count(),
                    'now' => now($timezone)->format('Y-m-d\TH:i'),
                ]
                : null,
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

    /**
     * Step 4.5: the responsible doctor hands the admission over to another professional qualified at the
     * unit; a new row in the history, nothing rewritten. Access follows at once.
     */
    public function handOver(Request $request, Admission $admission): RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($admission->responsible_professional_id === $professional->id, 403);
        $data = $request->validate(['professional_id' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:500']], [], ['professional_id' => 'novo responsável', 'reason' => 'motivo']);
        $to = (int) $data['professional_id'];
        if ($to === $professional->id || ! in_array($to, array_column(AdmissionController::requestOptions($admission->unit_id)['professionals'], 'id'), true)) {
            throw ValidationException::withMessages(['professional_id' => 'Escolha outro profissional habilitado nesta unidade.']);
        }

        DB::transaction(function () use ($admission, $professional, $to, $data) {
            $locked = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, Admission::ACTIVE, true) || $locked->responsible_professional_id !== $professional->id) {
                throw ValidationException::withMessages(['professional_id' => 'A internação mudou nesse meio-tempo. Recarregue a página.']);
            }
            // Step 4.6: the transfer names the destination's responsible; no hand-over while it is open.
            if (AdmissionTransfer::query()->where('admission_id', $locked->id)->whereIn('status', AdmissionTransfer::OPEN)->exists()) {
                throw ValidationException::withMessages(['professional_id' => 'Há uma transferência entre unidades em andamento. Conclua ou cancele antes de passar a responsabilidade.']);
            }
            // Checked again under the lock: a qualification or account may have ended in between.
            if (! in_array($to, array_column(AdmissionController::requestOptions($locked->unit_id)['professionals'], 'id'), true)) {
                throw ValidationException::withMessages(['professional_id' => 'Escolha outro profissional habilitado nesta unidade.']);
            }
            $locked->forceFill(['responsible_professional_id' => $to])->save();
            $reason = trim((string) ($data['reason'] ?? ''));
            AdmissionController::movement($locked, ['kind' => 'responsible', 'from_professional_id' => $professional->id, 'to_professional_id' => $to, 'reason' => $reason === '' ? null : $reason]);
            AuditEvent::record('admission.responsible_changed', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id, 'from_professional_id' => $professional->id, 'to_professional_id' => $to]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Responsabilidade passada.']);

        return to_route('ward.index');
    }

    /**
     * Step 4.7: the responsible doctor discharges. Improvement, at request and transfer out of the network
     * carry a summary signed with the password (a clinical note of the admission, immutable); death and
     * evasion record when it happened and who registered it. The bed goes to cleaning and the ward access
     * ends with the admission. Drafts of other authors stay unsigned and are never deleted (ADR-0004).
     */
    public function discharge(Request $request, Admission $admission): RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($admission->responsible_professional_id === $professional->id, 403);
        $summaryTypes = implode(',', Admission::SUMMARY_REQUIRED);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Admission::DISCHARGE_TYPES))],
            'summary' => ['exclude_unless:type,'.$summaryTypes, 'required', 'string', 'max:20000'],
            'password' => ['exclude_unless:type,'.$summaryTypes, 'required', 'string'],
            'occurred_at' => ['exclude_if:type,'.$summaryTypes, 'required', 'date_format:Y-m-d\TH:i'],
        ], [], ['type' => 'tipo de alta', 'summary' => 'sumário de alta', 'password' => 'senha', 'occurred_at' => 'horário']);
        $withSummary = in_array($data['type'], Admission::SUMMARY_REQUIRED, true);
        $at = $withSummary ? now() : Carbon::createFromFormat('Y-m-d\TH:i', $data['occurred_at'], $admission->unit()->value('timezone') ?? 'UTC')->utc();
        if ($at->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => 'O horário não pode estar no futuro.']);
        }
        if ($withSummary) {
            VisitController::confirmPassword($request, $data['password'], 'admission.discharge_sign_failed', $admission, 'Senha incorreta. A alta não foi registrada.');
        }

        DB::transaction(function () use ($admission, $professional, $data, $withSummary, $at, $request) {
            $locked = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'admitted' || $locked->responsible_professional_id !== $professional->id) {
                throw ValidationException::withMessages(['type' => 'A internação mudou nesse meio-tempo. Recarregue a página.']);
            }
            if (AdmissionTransfer::query()->where('admission_id', $locked->id)->whereIn('status', AdmissionTransfer::OPEN)->exists()) {
                throw ValidationException::withMessages(['type' => 'Há uma transferência entre unidades em andamento. Cancele-a antes da alta.']);
            }
            // The time is typed to the minute: within the admission's minute it is the admission itself.
            if ($at->lt($locked->admitted_at->copy()->startOfMinute())) {
                throw ValidationException::withMessages(['occurred_at' => 'O horário não pode ser anterior à internação.']);
            }
            $at = $at->max($locked->admitted_at);
            // The doctor's own draft would be orphaned (and the summary is one note per author): signed first.
            if (ClinicalNote::query()->where('admission_id', $locked->id)->where('author_user_id', $request->user()->id)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['type' => 'Assine seu rascunho de evolução desta internação antes da alta.']);
            }
            $summary = null;
            if ($withSummary) {
                // Notes start as drafts (database trigger); signed in the same transaction.
                $summary = ClinicalNote::query()->create([
                    'patient_id' => $locked->patient_id, 'admission_id' => $locked->id, 'author_user_id' => $request->user()->id,
                    'author_professional_id' => $professional->id, 'body' => $data['summary'],
                ]);
                $summary->forceFill(['status' => 'signed', 'signed_at' => now()])->save();
            }
            $bed = Bed::query()->findOrFail($locked->bed_id);
            $released = Bed::query()->whereKey($bed->id)->where('status', 'occupied')->update(['status' => 'cleaning', 'status_changed_at' => now(), 'updated_at' => now()]);
            if ($released === 0) {
                throw ValidationException::withMessages(['type' => 'O leito deixou de estar ocupado. Confira com a gestão de leitos.']);
            }
            BedController::event($bed, 'occupied', 'cleaning');
            $locked->forceFill([
                'status' => 'discharged', 'discharge_type' => $data['type'], 'discharged_at' => $at,
                'discharged_by_user_id' => $request->user()->id, 'discharge_summary_id' => $summary?->id,
            ])->save();
            // Ids only: the discharge type (death included) stays in the clinical table.
            AuditEvent::record('admission.discharged', $locked->tenant_id, $locked, array_filter(['unit_id' => $locked->unit_id, 'bed_id' => $bed->id, 'summary_id' => $summary?->id]));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alta registrada. O leito foi para higienização.']);

        return to_route('ward.index');
    }

    /**
     * The admission's history (step 4.5): beds and responsible doctors, oldest first. Bed management gets
     * the beds only: the hand-over reason is written by doctors and may carry clinical detail.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(Admission $admission, string $timezone, bool $bedsOnly = false): array
    {
        $rows = DB::table('admission_movements')->where('admission_id', $admission->id)->when($bedsOnly, fn ($query) => $query->where('kind', 'bed'))->orderBy('id')->get();
        $beds = Bed::query()->whereKey($rows->pluck('to_bed_id')->merge($rows->pluck('from_bed_id'))->filter()->unique()->all())->with('sector:id,name')->get()->keyBy('id');
        $people = Professional::query()->whereKey($rows->pluck('to_professional_id')->merge($rows->pluck('from_professional_id'))->filter()->unique()->all())->pluck('name', 'id');
        $bed = fn (?int $id) => $id === null || ! $beds->has($id) ? null : "{$beds[$id]->sector?->name} · quarto {$beds[$id]->room} · leito {$beds[$id]->code}";

        return array_values($rows->map(fn (\stdClass $row) => [
            'id' => (int) $row->id,
            'at' => Carbon::parse($row->occurred_at)->setTimezone($timezone)->format('d/m/Y H:i'),
            'text' => $row->kind === 'bed'
                ? ($row->from_bed_id === null ? 'Internado em '.$bed((int) $row->to_bed_id) : 'De '.$bed((int) $row->from_bed_id).' para '.$bed((int) $row->to_bed_id))
                : 'Responsável: de '.($people[$row->from_professional_id] ?? '—').' para '.($people[$row->to_professional_id] ?? '—'),
            'reason' => $row->reason,
        ])->all());
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
