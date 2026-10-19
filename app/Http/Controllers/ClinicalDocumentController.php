<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\ClinicalDocument;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Visit;
use Carbon\CarbonInterface;
use finfo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Clinical documents (step 3.7): attached during an open visit or, since step 4.4b, an admission the
 * professional has access to; downloaded only with clinical access (each
 * download logged), withdrawn with a reason (the file stays). The type is checked by content, never by the
 * name, and the file is always served as a download.
 */
class ClinicalDocumentController extends Controller
{
    public function store(Request $request, Visit $visit): RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($visit->professional_id === $professional->id && $visit->closed_at === null, 403);

        return self::attach($request, $professional, $visit->tenant_id, $visit->patient_id, ['visit_id' => $visit->id], 'Este atendimento', function () use ($visit) {
            // Locked like close(): nothing is attached to a visit closed in the meantime.
            if (Visit::query()->whereKey($visit->id)->lockForUpdate()->value('closed_at') !== null) {
                throw ValidationException::withMessages(['file' => 'Este atendimento já foi encerrado.']);
            }
        });
    }

    /**
     * Step 4.4b (ADR-0004): the responsible doctor or the ward team attaches during the admission.
     */
    public function storeForAdmission(Request $request, Admission $admission): RedirectResponse
    {
        $professional = self::professional($request);
        $patient = Patient::query()->findOrFail($admission->patient_id);
        abort_unless(ClinicalAccess::activeAdmission($request->user(), $patient)?->id === $admission->id, 403);

        return self::attach($request, $professional, $admission->tenant_id, $admission->patient_id, ['admission_id' => $admission->id], 'Esta internação', function () use ($admission, $patient, $request) {
            // Locked like the admission notes: still active, and the access checked again under the lock.
            if (! in_array(Admission::query()->whereKey($admission->id)->lockForUpdate()->value('status'), Admission::ACTIVE, true)) {
                throw ValidationException::withMessages(['file' => 'Esta internação foi encerrada.']);
            }
            abort_unless(ClinicalAccess::activeAdmission($request->user(), $patient)?->id === $admission->id, 403);
        });
    }

    /**
     * @param  array{visit_id: int}|array{admission_id: int}  $origin
     * @param  callable(): void  $lockOrigin  runs first inside the transaction
     */
    private static function attach(Request $request, Professional $professional, int $tenantId, int $patientId, array $origin, string $label, callable $lockOrigin): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.ClinicalDocument::MAX_KILOBYTES, 'extensions:'.implode(',', array_keys(ClinicalDocument::TYPES))],
        ], [
            // Above PHP's upload limit the file never arrives; both cases mean "too big" to the user.
            'file.uploaded' => 'O arquivo não chegou inteiro. Envie um PDF, JPG ou PNG de até 10 MB.',
            'file.max' => 'O arquivo passa de 10 MB.',
        ], ['file' => 'arquivo']);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        // The type comes from the bytes, never from the name or the browser: a page saved as .pdf, or a PNG
        // renamed to .pdf, is refused.
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if ($detected !== ClinicalDocument::TYPES[$extension]) {
            throw ValidationException::withMessages(['file' => 'O conteúdo do arquivo não é um '.strtoupper($extension === 'jpeg' ? 'jpg' : $extension).' válido.']);
        }

        $path = $file->storeAs("clinical/{$tenantId}", Str::random(40).'.'.$extension, 'local');
        if ($path === false) {
            throw new RuntimeException('Clinical document could not be stored.');
        }
        try {
            DB::transaction(function () use ($origin, $label, $lockOrigin, $patientId, $request, $professional, $file, $path, $detected) {
                $lockOrigin();
                if (ClinicalDocument::query()->where($origin)->count() >= ClinicalDocument::MAX_PER_VISIT) {
                    throw ValidationException::withMessages(['file' => $label.' já tem '.ClinicalDocument::MAX_PER_VISIT.' documentos.']);
                }
                $document = ClinicalDocument::query()->create([
                    ...$origin, 'patient_id' => $patientId, 'uploaded_by_user_id' => $request->user()->id,
                    'professional_id' => $professional->id, 'original_name' => self::cleanName($file->getClientOriginalName()),
                    'mime_type' => $detected, 'size_bytes' => $file->getSize(), 'path' => $path,
                    'sha256' => hash_file('sha256', $file->getRealPath()), 'created_at' => now(),
                ]);
                AuditEvent::record('clinical_document.attached', $document->tenant_id, $document, $origin);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Documento anexado.']);

        return back();
    }

    public function download(Request $request, ClinicalDocument $document): StreamedResponse
    {
        self::professional($request);
        abort_if($document->withdrawn_at !== null, 410);
        // Never trust a stored path blindly: it must sit under this client's own prefix.
        abort_unless(str_starts_with($document->path, "clinical/{$document->tenant_id}/") && ! str_contains($document->path, '..'), 404);
        $patient = Patient::query()->findOrFail($document->patient_id);
        $basis = ClinicalAccess::activeVisit($request->user(), $patient) ?? ClinicalAccess::activeAdmission($request->user(), $patient)
            ?? ClinicalAccess::activeEmergency($request->user(), $patient) ?? abort(403);
        // A file missing on disk is a clear 404, and no read is logged for it.
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        ClinicalAccess::recordRead($request->user(), $patient, $basis);
        AuditEvent::record('clinical_document.downloaded', $document->tenant_id, $document, ['reason' => match (true) {
            $basis instanceof Visit => 'visit',
            $basis instanceof Admission => 'admission',
            default => 'emergency',
        }]);

        return Storage::disk('local')->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function withdraw(Request $request, ClinicalDocument $document): RedirectResponse
    {
        self::professional($request);
        // Only while the document's own visit is open, or its admission still gives the uploader access.
        $open = $document->visit_id !== null
            ? Visit::query()->whereKey($document->visit_id)->whereNull('closed_at')->exists()
            : ClinicalAccess::activeAdmission($request->user(), Patient::query()->findOrFail($document->patient_id))?->id === $document->admission_id;
        abort_unless($document->uploaded_by_user_id === $request->user()->id && $open, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motivo']);

        DB::transaction(function () use ($document, $request, $data) {
            $locked = ClinicalDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->withdrawn_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Este documento já foi retirado.']);
            }
            $locked->forceFill(['withdrawn_at' => now(), 'withdrawn_by_user_id' => $request->user()->id, 'withdraw_reason' => $data['reason']])->save();
            AuditEvent::record('clinical_document.withdrawn', $locked->tenant_id, $locked);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Documento retirado. O arquivo fica guardado para a auditoria.']);

        return back();
    }

    /**
     * The documents of a record, newest first. Callers must have checked ClinicalAccess.
     *
     * @param  list<int>  $patientIds
     * @return list<array<string, mixed>>
     */
    public static function listing(array $patientIds, string $timezone, int $userId): array
    {
        $when = fn (?CarbonInterface $at) => $at?->setTimezone($timezone)->format('d/m/Y H:i');

        return array_values(ClinicalDocument::query()->whereIn('patient_id', $patientIds)->with('professional:id,name')->latest('created_at')->latest('id')->get()
            ->map(fn (ClinicalDocument $document) => [
                'id' => $document->id,
                'name' => $document->original_name,
                'type' => $document->mime_type === 'application/pdf' ? 'PDF' : ($document->mime_type === 'image/png' ? 'PNG' : 'JPG'),
                'sizeKb' => (int) ceil($document->size_bytes / 1024),
                'attachedAt' => $when($document->created_at),
                'author' => $document->professional?->name,
                'withdrawnAt' => $when($document->withdrawn_at),
                'withdrawReason' => $document->withdraw_reason,
                'canWithdraw' => $document->withdrawn_at === null && $document->uploaded_by_user_id === $userId,
            ])->all());
    }

    private static function professional(Request $request): Professional
    {
        return ClinicalAccess::professional($request->user()) ?? abort(403);
    }

    /**
     * The name shown and offered on download: no paths, no control characters, at most 255 characters.
     */
    private static function cleanName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename($name)));

        return mb_substr($clean === '' ? 'documento' : $clean, 0, 255);
    }
}
