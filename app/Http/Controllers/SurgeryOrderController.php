<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Surgery;
use App\Models\SurgicalProcedure;
use App\Models\Unit;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Surgery orders (step 5b.2, delivery 5b): the professional who attends the open visit or the active admission, with
 * a function that attends patients and a qualification in the unit, orders a procedure of the catalog with the side,
 * the urgency, the wished date and the clinical indication, signed with the password (ADR-0009). Its author cancels
 * it with a reason before it starts. Clinical: the page records a read; the audit keeps ids and markers only.
 */
class SurgeryOrderController extends Controller
{
    public function forAdmission(Request $request, Admission $admission): Response
    {
        return $this->page($request, $admission, null);
    }

    public function forVisit(Request $request, Visit $visit): Response
    {
        return $this->page($request, null, $visit);
    }

    public function storeForAdmission(Request $request, Admission $admission): RedirectResponse
    {
        return $this->store($request, $admission, null);
    }

    public function storeForVisit(Request $request, Visit $visit): RedirectResponse
    {
        return $this->store($request, null, $visit);
    }

    public function cancel(Request $request, Surgery $surgery): RedirectResponse
    {
        // Only its author; also after the encounter closed, so a surgery never stays requested by mistake.
        abort_unless($surgery->author_user_id === $request->user()->id, 403);
        $bag = "cancel{$surgery->id}";
        $data = $request->validateWithBag($bag, ['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        DB::transaction(function () use ($request, $surgery, $data, $bag) {
            $locked = Surgery::query()->lockForUpdate()->findOrFail($surgery->id);
            // ponytail: only "requested" exists until step 5b.3 adds scheduling; it widens this to before the start.
            if ($locked->status !== 'requested') {
                throw ValidationException::withMessages(['reason' => 'Esta cirurgia não pode mais ser cancelada.'])->errorBag($bag);
            }
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $request->user()->id, 'cancellation_reason' => trim($data['reason'])]);
            AuditEvent::record('surgery.cancelled', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pedido de cirurgia cancelado.']);

        return back();
    }

    private function page(Request $request, ?Admission $admission, ?Visit $visit): Response
    {
        [$patient] = ExamOrderController::writer($request, $admission, $visit);
        ClinicalAccess::recordRead($request->user(), $patient, $admission ?? $visit);
        $surgeries = Surgery::query()->where($admission !== null ? 'admission_id' : 'visit_id', $admission->id ?? $visit?->id)->orderByDesc('id')->get();
        $names = User::query()->whereKey($surgeries->pluck('author_user_id')->unique()->all())->pluck('name', 'id');
        $timezone = self::timezone((int) ($admission->unit_id ?? $visit?->unit_id));

        return Inertia::render('surgery-orders/index', [
            'patient' => ['name' => $patient->name],
            'encounter' => $admission !== null ? ['type' => 'admission', 'id' => $admission->id] : ['type' => 'visit', 'id' => $visit?->id],
            'surgeries' => $surgeries->map(fn (Surgery $surgery) => [
                'id' => $surgery->id,
                'procedure' => $surgery->procedure_name,
                'laterality' => $surgery->laterality,
                'urgency' => $surgery->urgency,
                'desiredDate' => $surgery->desired_date->format('d/m/Y'),
                'indication' => $surgery->indication,
                'status' => $surgery->status,
                'author' => $names->get($surgery->author_user_id),
                'createdAt' => $surgery->created_at->timezone($timezone)->format('d/m/Y H:i'),
                'cancellationReason' => $surgery->cancellation_reason,
                'canCancel' => $surgery->status === 'requested' && $surgery->author_user_id === $request->user()->id,
            ])->values(),
            'catalog' => SurgicalProcedure::query()->where('active', true)->orderBy('name_key')->get(['id', 'name', 'duration_minutes'])->values(),
            'lateralities' => Surgery::LATERALITIES,
            'urgencies' => Surgery::URGENCIES,
            'today' => now($timezone)->toDateString(),
            'maxDate' => now($timezone)->addDays(Surgery::MAX_DAYS_AHEAD)->toDateString(),
        ]);
    }

    private function store(Request $request, ?Admission $admission, ?Visit $visit): RedirectResponse
    {
        [$patient, $professional] = ExamOrderController::writer($request, $admission, $visit);
        $today = now(self::timezone((int) ($admission->unit_id ?? $visit?->unit_id)));
        $data = $request->validateWithBag('order', [
            'surgical_procedure_id' => ['required', 'integer', Rule::exists('surgical_procedures', 'id')->where('active', true)->where('tenant_id', $patient->tenant_id)],
            'laterality' => ['required', Rule::in(array_keys(Surgery::LATERALITIES))],
            'urgency' => ['required', Rule::in(array_keys(Surgery::URGENCIES))],
            'desired_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString(), 'before_or_equal:'.$today->copy()->addDays(Surgery::MAX_DAYS_AHEAD)->toDateString()],
            'indication' => ['required', 'string', 'max:1000'],
            'password' => ['required', 'string'],
        ], [
            'desired_date.after_or_equal' => 'A data desejada não pode ser no passado.',
            'desired_date.before_or_equal' => 'A data desejada deve estar dentro de um ano.',
        ], ['surgical_procedure_id' => 'procedimento', 'laterality' => 'lateralidade', 'urgency' => 'caráter', 'desired_date' => 'data desejada', 'indication' => 'indicação clínica', 'password' => 'senha']);
        /** @var Admission|Visit $encounter */
        $encounter = $admission ?? $visit;
        try {
            VisitController::confirmPassword($request, $data['password'], 'surgery.sign_failed', $encounter, 'Senha incorreta. O pedido não foi feito.');
        } catch (ValidationException $e) {
            throw $e->errorBag('order');
        }

        DB::transaction(function () use ($request, $admission, $visit, $patient, $professional, $data) {
            // A close or a discharge racing the order waits on this lock, then the access is checked again.
            ($admission !== null ? Admission::query() : Visit::query())->lockForUpdate()->findOrFail($admission->id ?? $visit?->id);
            ExamOrderController::writer($request, $admission?->fresh(), $visit?->fresh());
            $procedure = SurgicalProcedure::query()->where('active', true)->whereKey((int) $data['surgical_procedure_id'])->firstOrFail();
            $surgery = Surgery::query()->create([
                'patient_id' => $patient->merged_into_id ?? $patient->id, 'unit_id' => $admission->unit_id ?? $visit?->unit_id,
                'admission_id' => $admission?->id, 'visit_id' => $visit?->id, 'author_user_id' => $request->user()->id, 'professional_id' => $professional->id,
                'surgical_procedure_id' => $procedure->id, 'procedure_name' => $procedure->name, 'laterality' => $data['laterality'], 'urgency' => $data['urgency'],
                'desired_date' => $data['desired_date'], 'indication' => trim($data['indication']), 'signature_method' => 'password', 'created_at' => now(),
            ]);
            AuditEvent::record('surgery.requested', $surgery->tenant_id, $surgery, ['unit_id' => $surgery->unit_id, 'urgency' => $surgery->urgency]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pedido de cirurgia feito.']);

        return back();
    }

    private static function timezone(int $unitId): string
    {
        return Unit::query()->whereKey($unitId)->value('timezone') ?? 'UTC';
    }
}
