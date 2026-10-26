<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\Surgery;
use App\Models\SurgeryDescription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The surgical description (step 5b.5, ADR-0009). A surgeon of the active team, qualified in the unit, signs it with
 * the password once the three checklist steps are confirmed; that completes the surgery, ends the record access of
 * the surgical center and the team, and tells who ordered it. For ADDENDUM_DAYS after, the surgeons of the team open
 * the surgery (without the record) to sign addenda; the original stays as it was (trigger).
 */
class SurgeryDescriptionController extends Controller
{
    public function store(Request $request, Surgery $surgery): RedirectResponse
    {
        abort_unless(self::canSign($request->user(), $surgery), 403);
        $data = $request->validateWithBag('description', [
            'findings' => ['required', 'string', 'max:5000'],
            'technique' => ['required', 'string', 'max:5000'],
            'complications' => ['required', 'string', 'max:5000'],
            'specimens' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'string'],
        ], [], ['findings' => 'achados', 'technique' => 'técnica', 'complications' => 'intercorrências', 'specimens' => 'peças enviadas', 'password' => 'senha']);
        try {
            VisitController::confirmPassword($request, $data['password'], 'surgery.description_sign_failed', $surgery, 'Senha incorreta. A descrição não foi assinada.');
        } catch (ValidationException $e) {
            throw $e->errorBag('description');
        }
        try {
            DB::transaction(function () use ($request, $surgery, $data) {
                // The surgery first, as the checklist and the team changes lock it.
                $locked = Surgery::query()->lockForUpdate()->findOrFail($surgery->id);
                abort_unless(self::canSign($request->user(), $locked), 403);
                if ($locked->status === 'completed') {
                    throw ValidationException::withMessages(['description' => 'A descrição desta cirurgia já foi assinada.'])->errorBag('description');
                }
                if ($locked->status !== 'in_progress' || ! $locked->checklist()->where('step', 'sign_out')->exists()) {
                    throw ValidationException::withMessages(['description' => 'Confirme as três etapas do checklist antes de assinar a descrição.'])->errorBag('description');
                }
                $description = $locked->description()->create([
                    'tenant_id' => $locked->tenant_id, 'findings' => trim($data['findings']), 'technique' => trim($data['technique']),
                    'complications' => trim($data['complications']), 'specimens' => self::optional($data['specimens'] ?? null),
                    'author_user_id' => $request->user()->id, 'professional_id' => ClinicalAccess::professional($request->user())?->id,
                    'signature_method' => 'password', 'signed_at' => now(),
                ]);
                $locked->update(['status' => 'completed', 'completed_at' => $description->signed_at]);
                // Ids only: the inbox delivery tells the author, with nothing clinical in the text.
                AuditEvent::record('surgery.completed', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id, 'author_user_id' => $locked->author_user_id, 'patient_id' => $locked->patient_id, 'signer_user_id' => $request->user()->id]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['description' => 'A descrição desta cirurgia já foi assinada.'])->errorBag('description');
        }
        // Step 6.2a: the completed surgery goes to the stay's account; a failure is reported, never undoes it.
        rescue(fn () => BillingAccount::ofStay($surgery->visit_id, $surgery->admission_id)?->generate());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Descrição assinada. Cirurgia concluída.']);

        return back();
    }

    public function addendum(Request $request, Surgery $surgery): RedirectResponse
    {
        abort_unless(self::canAddendum($request->user(), $surgery), 403);
        $data = $request->validateWithBag('addendum', [
            'body' => ['required', 'string', 'max:5000'],
            'password' => ['required', 'string'],
        ], [], ['body' => 'adendo', 'password' => 'senha']);
        try {
            VisitController::confirmPassword($request, $data['password'], 'surgery.description_sign_failed', $surgery, 'Senha incorreta. O adendo não foi assinado.');
        } catch (ValidationException $e) {
            throw $e->errorBag('addendum');
        }
        DB::transaction(function () use ($request, $surgery, $data) {
            $locked = Surgery::query()->lockForUpdate()->findOrFail($surgery->id);
            abort_unless(self::canAddendum($request->user(), $locked), 403);
            $description = $locked->description()->firstOrFail();
            if ($description->addenda()->count() >= SurgeryDescription::MAX_ADDENDA) {
                throw ValidationException::withMessages(['body' => 'Esta descrição já tem '.SurgeryDescription::MAX_ADDENDA.' adendos.'])->errorBag('addendum');
            }
            $addendum = $description->addenda()->create([
                'tenant_id' => $locked->tenant_id, 'surgery_id' => $locked->id, 'body' => trim($data['body']),
                'author_user_id' => $request->user()->id, 'professional_id' => ClinicalAccess::professional($request->user())?->id,
                'signature_method' => 'password', 'signed_at' => now(),
            ]);
            AuditEvent::record('surgery.description_addendum', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id, 'addendum_id' => $addendum->id, 'author_user_id' => $locked->author_user_id, 'patient_id' => $locked->patient_id, 'signer_user_id' => $request->user()->id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Adendo assinado.']);

        return back();
    }

    /**
     * ADR-0009: only a surgeon of the active team, qualified in the unit, signs the description.
     */
    public static function canSign(User $user, Surgery $surgery): bool
    {
        return ($professional = ClinicalAccess::professional($user)) !== null
            && $surgery->team()->where('professional_id', $professional->id)->where('role', 'surgeon')->exists()
            && SurgeryScheduleController::eligible($surgery->unit_id)->whereKey($professional->id)->exists();
    }

    /**
     * The same surgeons, for ADDENDUM_DAYS after the completion.
     */
    public static function canAddendum(User $user, Surgery $surgery): bool
    {
        return $surgery->status === 'completed' && $surgery->completed_at >= now()->subDays(SurgeryDescription::ADDENDUM_DAYS) && self::canSign($user, $surgery);
    }

    /**
     * The surgical descriptions of the record, newest first, each with its addenda (step 5b.5).
     *
     * @param  list<int>  $patientIds
     * @return list<array<string, mixed>>
     */
    public static function listing(array $patientIds, string $timezone): array
    {
        $surgeries = Surgery::query()->whereIn('patient_id', $patientIds)->where('status', 'completed')->whereHas('description')
            ->with(['description.addenda'])->orderByDesc('completed_at')->get();

        // One names query for the whole record.
        $descriptions = $surgeries->pluck('description');
        $names = User::query()->whereKey($descriptions->pluck('author_user_id')->merge($descriptions->pluck('addenda')->flatten()->pluck('author_user_id'))->unique()->values()->all())->pluck('name', 'id');

        return array_values($surgeries->map(fn (Surgery $surgery) => self::present($surgery, $timezone, $names))->all());
    }

    /**
     * One description with its addenda, the surgery's description loaded; the authors' names given or looked up.
     *
     * @param  Collection<int, string>|null  $names
     * @return array<string, mixed>
     */
    public static function present(Surgery $surgery, string $timezone, ?Collection $names = null): array
    {
        /** @var SurgeryDescription $description */
        $description = $surgery->description;
        $names ??= User::query()->whereKey([$description->author_user_id, ...$description->addenda->pluck('author_user_id')->all()])->pluck('name', 'id');
        $signed = fn (SurgeryDescription $row) => ['author' => $names->get($row->author_user_id), 'signedAt' => $row->signed_at->timezone($timezone)->format('d/m/Y H:i')];

        return [
            'id' => $description->id,
            'procedure' => $surgery->procedure_name,
            'laterality' => $surgery->laterality,
            'findings' => $description->findings,
            'technique' => $description->technique,
            'complications' => $description->complications,
            'specimens' => $description->specimens,
            ...$signed($description),
            'addenda' => $description->addenda->map(fn (SurgeryDescription $addendum) => ['id' => $addendum->id, 'body' => $addendum->body, ...$signed($addendum)])->values()->all(),
        ];
    }

    private static function optional(?string $value): ?string
    {
        return ($value = trim((string) $value)) === '' ? null : $value;
    }
}
