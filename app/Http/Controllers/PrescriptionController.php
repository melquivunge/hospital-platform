<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Clinical\PrescriptionSafety;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\JobFunction;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Unit;
use App\Models\User;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Prescription (step 4b.2a, ADR-0005/0006). Only a doctor (council CRM, a function that attends patients,
 * qualified in the unit) with write access to the encounter prescribes: the admission (ADR-0004) or an open
 * emergency visit. The draft is seen only by its author; signing re-checks every safety alert against its
 * justification and freezes the prescription for 24 hours. Audit keeps ids and counts only.
 */
class PrescriptionController extends Controller
{
    private const MAX_ITEMS = 50;

    public function startForAdmission(Request $request, Admission $admission): RedirectResponse
    {
        return self::start($request, $admission, null);
    }

    public function startForVisit(Request $request, Visit $visit): RedirectResponse
    {
        return self::start($request, null, $visit);
    }

    public function edit(Request $request, Prescription $prescription): Response
    {
        [$admission, $visit, $patient] = self::draft($request, $prescription);
        $basis = $admission ?? $visit;
        ClinicalAccess::recordRead($request->user(), $patient, $basis);
        Inertia::encryptHistory();
        $timezone = Unit::query()->whereKey($basis->unit_id)->value('timezone') ?? 'UTC';
        $ids = ClinicalAccess::recordIds($patient);
        $items = $prescription->items()->get()->all();
        $current = PrescriptionSafety::alerts($items, $ids);
        if ($admission !== null) {
            $admission->load(['bed:id,code,room,sector_id', 'bed.sector:id,name']);
        }

        return Inertia::render('prescriptions/edit', [
            'prescription' => [
                'id' => $prescription->id,
                'encounter' => $admission !== null ? (WardController::bedLabel($admission) ?? 'Internação aguardando leito') : 'Atendimento de urgência',
                'backUrl' => $admission !== null ? route('ward.show', $admission) : route('visits.show', $visit),
            ],
            'patient' => VisitController::patientHeader($patient),
            'allergies' => PatientAllergyController::listing($ids, $timezone),
            'items' => array_map(fn (PrescriptionItem $item, array $alerts) => [
                ...self::describe($item, $timezone),
                'alerts' => $alerts,
                // An alert the stored justification never saw must be justified again before signing.
                'pending' => array_values(array_diff($alerts, $item->justification === null ? [] : $item->alerts)),
            ], $items, $current),
            'medications' => array_values(Medication::query()->where('active', true)->orderBy('active_ingredient')->orderBy('form')->orderBy('strength')->get()
                // ponytail: the whole active catalog in a <select>; search on the server if it grows past a few thousand.
                ->map(fn (Medication $medication) => [
                    'id' => $medication->id, 'label' => self::label($medication), 'doseUnit' => $medication->dose_unit,
                    'routes' => array_map(fn (string $route) => ['value' => $route, 'label' => Medication::ROUTES[$route] ?? $route], $medication->routes),
                    'controlled' => $medication->controlled, 'highAlert' => $medication->high_alert,
                ])->all()),
            'frequencies' => PrescriptionItem::FREQUENCIES,
            'now' => now($timezone)->format('Y-m-d\TH:i'),
        ]);
    }

    public function storeItem(Request $request, Prescription $prescription): RedirectResponse
    {
        [, , $patient] = self::draft($request, $prescription);
        $data = $request->validate([
            'medication_id' => ['required', 'integer'],
            'dose' => ['required', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'route' => ['required', 'string'],
            'schedule' => ['required', Rule::in(['interval', 'times'])],
            'frequency_hours' => ['exclude_unless:schedule,interval', 'required', 'integer', Rule::in(PrescriptionItem::FREQUENCIES)],
            'times' => ['exclude_unless:schedule,times', 'required', 'string', 'max:200'],
            'prn' => ['boolean'],
            'prn_condition' => ['exclude_unless:prn,1', 'required', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'notes' => ['nullable', 'string', 'max:500'],
            'justification' => ['nullable', 'string', 'max:500'],
        ], ['prn_condition.required' => 'Diga em que condição o item "se necessário" é dado.'], [
            'medication_id' => 'medicamento', 'dose' => 'dose', 'route' => 'via', 'frequency_hours' => 'intervalo', 'times' => 'horários',
            'prn_condition' => 'condição', 'starts_at' => 'início', 'notes' => 'observação', 'justification' => 'justificativa',
        ]);
        $medication = Medication::query()->where('active', true)->find((int) $data['medication_id'])
            ?? throw ValidationException::withMessages(['medication_id' => 'Escolha um medicamento ativo do catálogo.']);
        if (! in_array($data['route'], $medication->routes, true)) {
            throw ValidationException::withMessages(['route' => 'Esta via não está cadastrada para o medicamento.']);
        }
        $prn = (bool) ($data['prn'] ?? false);
        if ($prn && $data['schedule'] !== 'interval') {
            throw ValidationException::withMessages(['schedule' => 'O item "se necessário" usa um intervalo mínimo entre as doses.']);
        }
        $timezone = Unit::query()->whereKey($prescription->unit_id)->value('timezone') ?? 'UTC';
        $justification = isset($data['justification']) ? (Medication::clean($data['justification']) ?: null) : null;
        $item = new PrescriptionItem([
            'prescription_id' => $prescription->id, 'medication_id' => $medication->id, 'medication_label' => self::label($medication),
            'dose' => $data['dose'], 'dose_unit' => $medication->dose_unit, 'route' => $data['route'],
            'frequency_hours' => $data['schedule'] === 'interval' ? (int) $data['frequency_hours'] : null,
            'times' => $data['schedule'] === 'times' ? self::times($data['times']) : null,
            'prn' => $prn, 'prn_condition' => $prn ? Medication::clean($data['prn_condition']) : null,
            'starts_at' => isset($data['starts_at']) ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['starts_at'], $timezone)?->utc() : now(),
            'notes' => isset($data['notes']) ? (Medication::clean($data['notes']) ?: null) : null,
            'justification' => $justification, 'created_at' => now(),
        ]);

        DB::transaction(function () use ($prescription, $item, $patient, $justification) {
            $locked = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['medication_id' => 'Esta prescrição já foi assinada.']);
            }
            $existing = $locked->items()->get()->all();
            if (count($existing) >= self::MAX_ITEMS) {
                throw ValidationException::withMessages(['medication_id' => 'A prescrição já tem '.self::MAX_ITEMS.' itens.']);
            }
            $alerts = PrescriptionSafety::alerts([...$existing, $item], ClinicalAccess::recordIds($patient));
            $item->alerts = end($alerts) ?: [];
            if ($item->alerts !== [] && $justification === null) {
                throw ValidationException::withMessages(['justification' => 'Justifique para prescrever: '.implode(' ', $item->alerts)]);
            }
            if ($item->alerts === []) {
                $item->justification = null;
            }
            $item->save();
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Item incluído.']);

        return back();
    }

    public function justifyItem(Request $request, PrescriptionItem $item): RedirectResponse
    {
        $prescription = Prescription::query()->findOrFail($item->prescription_id);
        [, , $patient] = self::draft($request, $prescription);
        $data = $request->validateWithBag("justify{$item->id}", ['justification' => ['required', 'string', 'max:500']], [], ['justification' => 'justificativa']);

        DB::transaction(function () use ($prescription, $item, $patient, $data) {
            $locked = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status !== 'draft', 409);
            $items = $locked->items()->get()->all();
            $alerts = PrescriptionSafety::alerts($items, ClinicalAccess::recordIds($patient));
            $index = array_search($item->id, array_map(fn (PrescriptionItem $each) => $each->id, $items), true);
            abort_if($index === false, 404);
            $items[$index]->forceFill(['alerts' => $alerts[$index], 'justification' => Medication::clean($data['justification'])])->save();
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Justificativa registrada.']);

        return back();
    }

    public function destroyItem(Request $request, PrescriptionItem $item): RedirectResponse
    {
        self::draft($request, Prescription::query()->findOrFail($item->prescription_id));
        $item->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Item retirado do rascunho.']);

        return back();
    }

    public function discard(Request $request, Prescription $prescription): RedirectResponse
    {
        [$admission, $visit] = self::draft($request, $prescription);
        $prescription->delete();
        AuditEvent::record('prescription.discarded', $prescription->tenant_id, $prescription);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rascunho descartado.']);

        return $admission !== null ? to_route('ward.show', $admission) : to_route('visits.show', $visit);
    }

    public function sign(Request $request, Prescription $prescription): RedirectResponse
    {
        [$admission, $visit, $patient] = self::draft($request, $prescription);
        $request->validate(['password' => ['required', 'string']], [], ['password' => 'senha']);
        VisitController::confirmPassword($request, $request->string('password')->toString(), 'prescription.sign_failed', $prescription, 'Senha incorreta. A prescrição não foi assinada.');

        $count = DB::transaction(function () use ($request, $prescription, $admission, $visit, $patient) {
            // Lock order of delivery 4: the encounter first, then the prescription. A discharge or a closing
            // visit in between is seen here.
            $open = $admission !== null
                ? in_array(Admission::query()->whereKey($admission->id)->lockForUpdate()->value('status'), Admission::ACTIVE, true)
                : Visit::query()->whereKey($visit?->id)->lockForUpdate()->value('closed_at') === null;
            if (! $open) {
                throw ValidationException::withMessages(['password' => 'O atendimento ou a internação foi encerrado. A prescrição não foi assinada.']);
            }
            self::draft($request, $prescription);
            $locked = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['password' => 'Esta prescrição já foi assinada.']);
            }
            $items = $locked->items()->get()->all();
            if ($items === []) {
                throw ValidationException::withMessages(['password' => 'Inclua ao menos um item antes de assinar.']);
            }
            // Every alert found now must have been justified: rules, allergies or other items may have changed.
            $alerts = PrescriptionSafety::alerts($items, ClinicalAccess::recordIds($patient));
            $pending = [];
            foreach ($items as $index => $item) {
                if ($alerts[$index] !== [] && ($item->justification === null || array_diff($alerts[$index], $item->alerts) !== [])) {
                    $pending[] = $item->medication_label;
                }
            }
            if ($pending !== []) {
                throw ValidationException::withMessages(['password' => 'Há alertas sem justificativa em: '.implode('; ', $pending).'. Justifique antes de assinar.']);
            }
            foreach ($items as $index => $item) {
                $item->forceFill(['alerts' => $alerts[$index], 'justification' => $alerts[$index] === [] ? null : $item->justification])->save();
            }
            $now = now();
            $locked->forceFill(['status' => 'signed', 'signed_at' => $now, 'valid_until' => $now->copy()->addHours(Prescription::VALID_HOURS), 'signature_method' => 'password'])->save();
            AuditEvent::record('prescription.signed', $locked->tenant_id, $locked, [
                'items' => count($items), 'alerts' => count(array_filter($alerts)),
            ]);

            return count($items);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => "Prescrição assinada com {$count} ".($count === 1 ? 'item' : 'itens').'. Vale por 24 horas.']);

        return $admission !== null ? to_route('ward.show', $admission) : to_route('visits.show', $visit);
    }

    /**
     * The prescription section of the record pages: the active one (the newest signed, while valid), the earlier
     * ones, the user's draft and whether the user may prescribe. Callers must have checked ClinicalAccess.
     *
     * @return array<string, mixed>
     */
    public static function panel(User $user, ?Admission $admission, ?Visit $visit, string $timezone): array
    {
        $column = $admission !== null ? 'admission_id' : 'visit_id';
        $id = $admission->id ?? $visit?->id;
        $signed = Prescription::query()->where($column, $id)->where('status', 'signed')->with(['items', 'professional:id,name'])
            ->orderByDesc('signed_at')->orderByDesc('id')->limit(10)->get();
        $newest = $signed->first();
        $active = $newest !== null && $newest->valid_until?->isFuture() ? $newest : null;
        $when = fn (?CarbonImmutable $at) => $at?->setTimezone($timezone)->format('d/m/Y H:i');
        $summary = fn (Prescription $prescription) => [
            'id' => $prescription->id,
            'signedAt' => $when($prescription->signed_at?->toImmutable()),
            'validUntil' => $when($prescription->valid_until?->toImmutable()),
            'author' => $prescription->professional?->name,
            'items' => array_map(fn (PrescriptionItem $item) => self::describe($item, $timezone), $prescription->items->all()),
        ];

        return [
            'active' => $active === null ? null : $summary($active),
            'previous' => array_values($signed->reject(fn (Prescription $prescription) => $prescription->is($active))->map($summary)->all()),
            'draftId' => Prescription::query()->where($column, $id)->where('status', 'draft')->where('author_user_id', $user->id)->value('id'),
            'canPrescribe' => self::prescriber($user, (int) ($admission->unit_id ?? $visit?->unit_id)) !== null,
        ];
    }

    /**
     * A doctor: council CRM and an approved qualification, valid today in the unit, in a function that attends
     * patients (decision 7 of 19/10/2026).
     */
    public static function prescriber(User $user, int $unitId): ?Professional
    {
        $professional = ClinicalAccess::professional($user);
        if ($professional === null || mb_strtoupper((string) $professional->council) !== 'CRM') {
            return null;
        }
        $today = now(Unit::query()->whereKey($unitId)->value('timezone') ?? 'UTC')->toDateString();

        return Qualification::query()->where('professional_id', $professional->id)->where('unit_id', $unitId)->where('status', QualificationStatus::Approved)
            ->whereIn('job_function_id', JobFunction::query()->where('attends_patients', true)->select('id'))
            ->whereDate('valid_from', '<=', $today)->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today))
            ->exists() ? $professional : null;
    }

    private static function start(Request $request, ?Admission $admission, ?Visit $visit): RedirectResponse
    {
        [$patient, $professional] = self::writer($request, $admission, $visit);
        $column = $admission !== null ? 'admission_id' : 'visit_id';
        $id = $admission->id ?? $visit?->id;
        $existing = fn () => Prescription::query()->where($column, $id)->where('status', 'draft')->where('author_user_id', $request->user()->id)->first();
        $prescription = $existing();
        if ($prescription === null) {
            try {
                $prescription = DB::transaction(fn () => Prescription::query()->create([
                    'patient_id' => $patient->merged_into_id ?? $patient->id, 'unit_id' => $admission->unit_id ?? $visit?->unit_id,
                    $column => $id, 'author_user_id' => $request->user()->id, 'professional_id' => $professional->id, 'created_at' => now(),
                ]));
                AuditEvent::record('prescription.created', $prescription->tenant_id, $prescription);
            } catch (UniqueConstraintViolationException) {
                // Two clicks at once: one draft.
                $prescription = $existing() ?? abort(409);
            }
        }

        return to_route('prescriptions.edit', $prescription);
    }

    /**
     * The patient and the prescriber, if the user writes on this open encounter and may prescribe; otherwise 403.
     *
     * @return array{Patient, Professional}
     */
    private static function writer(Request $request, ?Admission $admission, ?Visit $visit): array
    {
        $patient = Patient::query()->findOrFail($admission->patient_id ?? $visit?->patient_id);
        if ($admission !== null) {
            abort_unless(ClinicalAccess::activeAdmission($request->user(), $patient)?->id === $admission->id, 403);
        } else {
            // Only the emergency visit: the outpatient prescription (for home) is out of delivery 4b.
            abort_unless($visit !== null && $visit->closed_at === null && $visit->emergency_episode_id !== null
                && $visit->professional_id === ClinicalAccess::professional($request->user())?->id, 403);
        }
        $professional = self::prescriber($request->user(), (int) ($admission->unit_id ?? $visit?->unit_id)) ?? abort(403);

        return [$patient, $professional];
    }

    /**
     * The user's own draft, with write access to its encounter.
     *
     * @return array{Admission|null, Visit|null, Patient}
     */
    private static function draft(Request $request, Prescription $prescription): array
    {
        abort_unless($prescription->status === 'draft' && $prescription->author_user_id === $request->user()->id, 403);
        $admission = $prescription->admission_id === null ? null : Admission::query()->findOrFail($prescription->admission_id);
        $visit = $prescription->visit_id === null ? null : Visit::query()->findOrFail($prescription->visit_id);
        [$patient] = self::writer($request, $admission, $visit);

        return [$admission, $visit, $patient];
    }

    /**
     * "08:00, 14h, 20:00" → ["08:00", "14:00", "20:00"], sorted, no repeats.
     *
     * @return list<string>
     */
    private static function times(string $text): array
    {
        $times = [];
        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/^([01]?\d|2[0-3])(?:[:h]([0-5]\d)?)?$/i', $part, $match) !== 1) {
                throw ValidationException::withMessages(['times' => "Horário inválido: \"{$part}\". Use por exemplo 08:00, 14:00, 20:00."]);
            }
            $times[] = sprintf('%02d:%02d', (int) $match[1], (int) ($match[2] ?? 0));
        }
        $times = array_values(array_unique($times));
        sort($times);
        if ($times === [] || count($times) > 24) {
            throw ValidationException::withMessages(['times' => 'Informe de 1 a 24 horários.']);
        }

        return $times;
    }

    private static function label(Medication $medication): string
    {
        return "{$medication->active_ingredient} {$medication->strength} ({$medication->form})";
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(PrescriptionItem $item, string $timezone): array
    {
        $schedule = match (true) {
            $item->frequency_hours === 24 => '1 vez ao dia',
            $item->frequency_hours !== null => "de {$item->frequency_hours}/{$item->frequency_hours} h",
            default => 'às '.implode(', ', $item->times ?? []),
        };

        return [
            'id' => $item->id,
            'medication' => $item->medication_label,
            'dose' => PrescriptionSafety::number($item->dose).' '.$item->dose_unit,
            'route' => Medication::ROUTES[$item->route] ?? $item->route,
            'schedule' => $item->prn ? "se necessário, no máximo {$schedule}: {$item->prn_condition}" : $schedule,
            'startsAt' => $item->starts_at->setTimezone($timezone)->format('d/m/Y H:i'),
            'notes' => $item->notes,
            'storedAlerts' => $item->alerts,
            'justification' => $item->justification,
        ];
    }
}
