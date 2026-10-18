<?php

namespace App\Http\Controllers;

use App\Imports\ProfessionalCsvImporter;
use App\Jobs\ApplyImportBatch;
use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Unit;
use App\Policies\ImportBatchPolicy;
use App\Policies\UnitPolicy;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV imports (step 5a): upload → preview (no operational writes) → confirm → background apply →
 * reconciliation. Original files stay private in the tenant's folder.
 */
class ImportController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('create', ImportBatch::class);

        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);

        $batches = ImportBatch::query()
            ->when(! $membership->hasRole(Role::NetworkAdmin), fn (Builder $query) => $query->where('created_by_user_id', $request->user()->id))
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (ImportBatch $batch) => [
                ...$batch->only('id', 'source_system', 'filename', 'status', 'received'),
                'createdAt' => $batch->created_at->toIso8601String(),
            ]);

        return Inertia::render('imports/index', ['batches' => $batches]);
    }

    public function create(): Response
    {
        Gate::authorize('create', ImportBatch::class);

        return Inertia::render('imports/create', [
            'columns' => ProfessionalCsvImporter::COLUMNS,
            'maxLines' => ProfessionalCsvImporter::MAX_LINES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ImportBatch::class);

        $request->validate([
            'source_system' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN ._\-]+$/u'],
            'file' => ['required', 'file', 'extensions:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', 'max:'.intdiv(ProfessionalCsvImporter::MAX_BYTES, 1024)],
        ], attributes: ['source_system' => 'sistema de origem', 'file' => 'arquivo']);

        $file = $request->file('file');
        $parsed = ProfessionalCsvImporter::parse((string) $file->get());

        if ($parsed['errors'] !== []) {
            throw ValidationException::withMessages(['file' => $parsed['errors']]);
        }

        if ($parsed['rows'] === []) {
            throw ValidationException::withMessages(['file' => 'O arquivo não tem linhas de dados.']);
        }

        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);
        $unitIds = ImportBatchPolicy::importableUnitIds($membership);
        $units = Unit::query()->when($unitIds !== null, fn (Builder $query) => $query->whereIn('id', $unitIds ?? []))->get(['id', 'name']);
        $tenantId = app(CurrentTenant::class)->require()->id;

        // Random private name: the client's file name is never used as a path.
        $path = (string) $file->storeAs("imports/{$tenantId}", Str::uuid()->toString().'.csv', 'local');

        try {
            $batch = DB::transaction(function () use ($request, $file, $parsed, $units, $path) {
                $batch = ImportBatch::create([
                    'type' => 'professionals',
                    'source_system' => trim($request->string('source_system')->value()),
                    // Display only; control and bidi characters removed.
                    'filename' => mb_substr((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $file->getClientOriginalName()), 0, 255),
                    'path' => $path,
                    'status' => 'previewed',
                    'created_by_user_id' => $request->user()->id,
                ]);
                ProfessionalCsvImporter::preview($batch, $parsed['rows'], $units);
                AuditEvent::record('import.uploaded', $batch->tenant_id, $batch, ['source_system' => $batch->source_system, 'received' => $batch->received]);

                return $batch;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return to_route('imports.show', $batch);
    }

    public function show(Request $request, ImportBatch $batch): Response
    {
        Gate::authorize('view', $batch);

        $status = $request->string('status')->value();
        $items = $batch->items()
            ->when(in_array($status, ['ready', 'created', 'identical', 'conflict', 'rejected'], true), fn ($query) => $query->where('status', $status))
            ->orderBy('line')
            ->paginate(100)
            ->withQueryString()
            ->through(fn (ImportItem $item) => [
                'id' => $item->id,
                'line' => $item->line,
                'sourceId' => $item->data['source_id'] ?? null,
                'name' => $item->data['name'] ?? null,
                'status' => $item->status,
                'messages' => $item->messages ?? [],
            ]);

        return Inertia::render('imports/show', [
            'batch' => [
                ...$batch->only('id', 'source_system', 'filename', 'status', 'received'),
                'createdAt' => $batch->created_at->toIso8601String(),
            ],
            'counts' => $batch->counts(),
            'items' => $items,
            'filter' => $status ?: null,
        ]);
    }

    /**
     * Confirmation applies only the lines that were valid in the preview. Confirming twice, or
     * resuming after a failure, dispatches the same idempotent job.
     */
    public function confirm(Request $request, ImportBatch $batch): RedirectResponse
    {
        Gate::authorize('view', $batch);

        $updated = ImportBatch::query()->whereKey($batch->id)->whereIn('status', ['previewed', 'applying'])
            ->update(['status' => 'applying', 'confirmed_at' => DB::raw('COALESCE(confirmed_at, now())')]);

        if ($updated === 0) {
            throw ValidationException::withMessages(['batch' => 'Esta importação já foi concluída.']);
        }

        AuditEvent::record('import.confirmed', $batch->tenant_id, $batch);
        ApplyImportBatch::dispatch($batch->tenant_id, $batch->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Importação confirmada. As linhas válidas estão sendo aplicadas.']);

        return to_route('imports.show', $batch);
    }

    /**
     * Header-only CSV template (no example data).
     */
    public function template(): StreamedResponse
    {
        Gate::authorize('create', ImportBatch::class);

        return response()->streamDownload(function () {
            echo implode(';', ProfessionalCsvImporter::COLUMNS)."\r\n";
        }, 'modelo-profissionais.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
