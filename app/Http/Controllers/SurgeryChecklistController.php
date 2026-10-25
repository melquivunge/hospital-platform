<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\AuditEvent;
use App\Models\ClinicalDocument;
use App\Models\Patient;
use App\Models\Surgery;
use App\Models\SurgeryChecklistStep;
use App\Models\SurgeryConsent;
use App\Models\User;
use App\Policies\UnitPolicy;
use finfo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The WHO safe surgery checklist and the consent term (step 5b.4, ADR-0009). The unit's Surgical center or a member
 * of the active team attaches the signed term (PDF, JPG or PNG, checked by content) or, for an urgent surgery, the
 * reason to go without one; then confirms the three steps in order, each once, with the answer to every fixed item.
 * The first step puts the surgery in progress. Nothing confirmed ever changes (trigger); the audit keeps ids only.
 */
class SurgeryChecklistController extends Controller
{
    public function storeConsent(Request $request, Surgery $surgery): RedirectResponse
    {
        self::authorizeConfirm($request->user(), $surgery);
        $request->validateWithBag('consent', [
            'file' => ['required', 'file', 'max:'.ClinicalDocument::MAX_KILOBYTES, 'extensions:'.implode(',', array_keys(ClinicalDocument::TYPES))],
        ], [
            'file.uploaded' => 'O arquivo não chegou inteiro. Envie um PDF, JPG ou PNG de até 10 MB.',
            'file.max' => 'O arquivo passa de 10 MB.',
        ], ['file' => 'arquivo']);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        // The type comes from the bytes, never from the name or the browser.
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if ($detected !== ClinicalDocument::TYPES[$extension]) {
            throw ValidationException::withMessages(['file' => 'O conteúdo do arquivo não é um '.strtoupper($extension === 'jpeg' ? 'jpg' : $extension).' válido.'])->errorBag('consent');
        }
        $path = $file->storeAs("clinical/{$surgery->tenant_id}", Str::random(40).'.'.$extension, 'local');
        if ($path === false) {
            throw new RuntimeException('Surgery consent could not be stored.');
        }
        try {
            self::addConsent($request, $surgery, [
                'kind' => 'document', 'path' => $path, 'original_name' => ClinicalDocumentController::cleanName($file->getClientOriginalName()),
                'mime_type' => $detected, 'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Termo de consentimento anexado.']);

        return back();
    }

    public function waiveConsent(Request $request, Surgery $surgery): RedirectResponse
    {
        self::authorizeConfirm($request->user(), $surgery);
        abort_unless($surgery->urgency === 'urgent', 403);
        $data = $request->validateWithBag('waiver', ['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        self::addConsent($request, $surgery, ['kind' => 'waiver', 'waiver_reason' => trim($data['reason'])]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Urgência sem termo registrada.']);

        return back();
    }

    public function confirm(Request $request, Surgery $surgery, string $step): RedirectResponse
    {
        abort_unless(array_key_exists($step, SurgeryChecklistStep::STEPS), 404);
        self::authorizeConfirm($request->user(), $surgery);
        $items = SurgeryChecklistStep::STEPS[$step]['items'];
        $bag = "step-{$step}";
        $data = $request->validateWithBag($bag, collect($items)->mapWithKeys(fn (array $item, string $key) => ["answers.{$key}" => ['required', Rule::in($item[1])]])->all(),
            ['answers.*.required' => 'Responda este item.', 'answers.*.in' => 'Resposta inválida para este item.']);
        $previous = array_search($step, array_keys(SurgeryChecklistStep::STEPS), true) - 1;
        try {
            DB::transaction(function () use ($request, $surgery, $step, $data, $bag, $previous) {
                // The surgery first, as cancel and reschedule lock it: one of them wins.
                $locked = Surgery::query()->lockForUpdate()->findOrFail($surgery->id);
                // Taken off the team or the qualification lapsed while the request was in flight (security review 5b.7).
                abort_unless(self::canConfirm($request->user(), $locked), 403);
                $expected = $step === 'sign_in' ? 'scheduled' : 'in_progress';
                if ($locked->status !== $expected) {
                    throw ValidationException::withMessages(['step' => $locked->status === 'cancelled' ? 'Esta cirurgia foi cancelada.' : 'Esta etapa não pode ser confirmada agora. Recarregue a página.'])->errorBag($bag);
                }
                if ($previous >= 0 && ! $locked->checklist()->where('step', array_keys(SurgeryChecklistStep::STEPS)[$previous])->exists()) {
                    throw ValidationException::withMessages(['step' => 'Confirme a etapa anterior primeiro.'])->errorBag($bag);
                }
                if ($step === 'sign_in' && ! $locked->consents()->exists()) {
                    throw ValidationException::withMessages(['step' => $locked->urgency === 'urgent' ? 'Anexe o termo de consentimento ou registre o motivo da urgência sem termo.' : 'Anexe o termo de consentimento assinado antes da primeira etapa.'])->errorBag($bag);
                }
                $confirmed = $locked->checklist()->create(['tenant_id' => $locked->tenant_id, 'step' => $step, 'answers' => $data['answers'], 'confirmed_by_user_id' => $request->user()->id, 'confirmed_at' => now()]);
                if ($step === 'sign_in') {
                    $locked->update(['status' => 'in_progress', 'started_at' => $confirmed->confirmed_at]);
                }
                AuditEvent::record('surgery.checklist_confirmed', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id, 'step' => $step]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['step' => 'Esta etapa já foi confirmada.'])->errorBag($bag);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => SurgeryChecklistStep::STEPS[$step]['label'].': etapa confirmada.']);

        return back();
    }

    public function downloadConsent(Request $request, SurgeryConsent $consent): StreamedResponse
    {
        abort_if($consent->kind !== 'document', 404);
        $surgery = Surgery::query()->findOrFail($consent->surgery_id);
        $patient = Patient::query()->findOrFail($surgery->patient_id);
        // The surgical center and the team while the surgery is open, or whoever attends the patient.
        $basis = ClinicalAccess::openSurgeries($request->user())->whereKey($surgery->id)->first()
            ?? ClinicalAccess::activeVisit($request->user(), $patient) ?? ClinicalAccess::activeAdmission($request->user(), $patient) ?? abort(403);
        abort_unless(str_starts_with((string) $consent->path, "clinical/{$consent->tenant_id}/") && ! str_contains((string) $consent->path, '..'), 404);
        abort_unless(Storage::disk('local')->exists((string) $consent->path), 404);
        ClinicalAccess::recordRead($request->user(), $patient, $basis);
        AuditEvent::record('surgery_consent.downloaded', $consent->tenant_id, $consent, ['surgery_id' => $surgery->id]);

        return Storage::disk('local')->download((string) $consent->path, (string) $consent->original_name, [
            'Content-Type' => (string) $consent->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * ADR-0009: the Surgical center of the surgery's unit, or a member of its active team (qualified in the unit).
     */
    public static function canConfirm(User $user, Surgery $surgery): bool
    {
        return in_array($surgery->unit_id, SurgeryScheduleController::unitIds(UnitPolicy::currentMembership($user)), true)
            || (($professional = ClinicalAccess::professional($user)) !== null
                && $surgery->team()->where('professional_id', $professional->id)->exists()
                && SurgeryScheduleController::eligible($surgery->unit_id)->whereKey($professional->id)->exists());
    }

    private static function authorizeConfirm(User $user, Surgery $surgery): void
    {
        abort_unless(self::canConfirm($user, $surgery), 403);
    }

    /**
     * Before the first step only: once in progress the term that counted is the one already there.
     *
     * @param  array<string, mixed>  $values
     */
    private static function addConsent(Request $request, Surgery $surgery, array $values): void
    {
        DB::transaction(function () use ($request, $surgery, $values) {
            $locked = Surgery::query()->lockForUpdate()->findOrFail($surgery->id);
            abort_unless(self::canConfirm($request->user(), $locked), 403);
            if (! in_array($locked->status, Surgery::OPEN, true)) {
                throw ValidationException::withMessages([$values['kind'] === 'waiver' ? 'reason' : 'file' => 'O termo só muda antes da primeira etapa do checklist.'])->errorBag($values['kind'] === 'waiver' ? 'waiver' : 'consent');
            }
            if ($locked->consents()->count() >= SurgeryConsent::MAX_PER_SURGERY) {
                throw ValidationException::withMessages([$values['kind'] === 'waiver' ? 'reason' : 'file' => 'Esta cirurgia já tem '.SurgeryConsent::MAX_PER_SURGERY.' termos registrados.'])->errorBag($values['kind'] === 'waiver' ? 'waiver' : 'consent');
            }
            $consent = $locked->consents()->create([...$values, 'tenant_id' => $locked->tenant_id, 'created_by_user_id' => $request->user()->id, 'created_at' => now()]);
            AuditEvent::record('surgery_consent.added', $locked->tenant_id, $locked, ['consent_id' => $consent->id, 'kind' => $consent->kind]);
        });
    }
}
