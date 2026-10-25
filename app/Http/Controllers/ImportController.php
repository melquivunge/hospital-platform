<?php

namespace App\Http\Controllers;

use App\Imports\CsvReader;
use App\Imports\ExamAnalyteCsvImporter;
use App\Imports\ExamCsvImporter;
use App\Imports\MaterialCsvImporter;
use App\Imports\MedicationCsvImporter;
use App\Imports\MedicationInteractionCsvImporter;
use App\Imports\ProfessionalCsvImporter;
use App\Imports\ShiftCsvImporter;
use App\Imports\StockCsvImporter;
use App\Imports\SurgicalProcedureCsvImporter;
use App\Jobs\ApplyImportBatch;
use App\Models\AuditEvent;
use App\Models\Exam;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Medication;
use App\Models\SurgicalProcedure;
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
use Illuminate\Validation\Rule;
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

    public function create(Request $request): Response
    {
        Gate::authorize('create', ImportBatch::class);

        return Inertia::render('imports/create', [
            'columns' => array_map(fn (string $importer) => $importer::COLUMNS, self::types($request)),
            'maxLines' => CsvReader::MAX_LINES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ImportBatch::class);

        $request->validate([
            'type' => ['required', Rule::in(array_keys(self::types($request)))],
            'source_system' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN ._\-]+$/u'],
            'file' => ['required', 'file', 'extensions:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', 'max:'.intdiv(CsvReader::MAX_BYTES, 1024)],
        ], attributes: ['source_system' => 'sistema de origem', 'file' => 'arquivo']);

        $file = $request->file('file');
        $type = $request->string('type')->value();
        $importer = self::importer($type);
        $parsed = CsvReader::parse((string) $file->get(), $importer::COLUMNS);

        if ($parsed['errors'] !== []) {
            throw ValidationException::withMessages(['file' => $parsed['errors']]);
        }

        if ($parsed['rows'] === []) {
            throw ValidationException::withMessages(['file' => 'O arquivo não tem linhas de dados.']);
        }

        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);
        $unitIds = ImportBatchPolicy::unitIdsFor($membership, $type);
        $units = Unit::query()->when($unitIds !== null, fn (Builder $query) => $query->whereIn('id', $unitIds ?? []))->get(['id', 'name']);
        $tenantId = app(CurrentTenant::class)->require()->id;

        // Random private name: the client's file name is never used as a path.
        $path = (string) $file->storeAs("imports/{$tenantId}", Str::uuid()->toString().'.csv', 'local');

        try {
            $batch = DB::transaction(function () use ($request, $file, $parsed, $units, $path, $type, $importer) {
                $batch = ImportBatch::create([
                    'type' => $type,
                    'source_system' => trim($request->string('source_system')->value()),
                    // Display only; control and bidi characters removed.
                    'filename' => mb_substr((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $file->getClientOriginalName()), 0, 255),
                    'path' => $path,
                    'status' => 'previewed',
                    'created_by_user_id' => $request->user()->id,
                ]);
                $importer::preview($batch, $parsed['rows'], $units);
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
                // Professionals: name; shifts: local start as sent in the file.
                'name' => $item->data['name'] ?? $item->data['start'] ?? null,
                'status' => $item->status,
                'messages' => $item->messages ?? [],
            ]);

        return Inertia::render('imports/show', [
            'batch' => [
                ...$batch->only('id', 'type', 'source_system', 'filename', 'status', 'received'),
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
        // Step 4b.1b: the catalog is the network's; confirming needs the right to keep it.
        abort_if(in_array($batch->type, self::CATALOG, true) && $request->user()->cannot('manage', Medication::class), 403);
        abort_if(in_array($batch->type, ['exams', 'exam_analytes'], true) && $request->user()->cannot('manage', Exam::class), 403);
        abort_if(in_array($batch->type, ['surgical_procedures', 'materials'], true) && $request->user()->cannot('manage', SurgicalProcedure::class), 403);
        // Step 4c.1b (ADR-0007): only the pharmacist who previewed it moves the stock, not a network admin.
        abort_if($batch->type === 'stock' && $batch->created_by_user_id !== $request->user()->id, 403);

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
    public function template(Request $request): StreamedResponse
    {
        Gate::authorize('create', ImportBatch::class);
        $types = self::types($request);
        $type = array_key_exists($request->string('type')->value(), $types) ? $request->string('type')->value() : (string) array_key_first($types);
        $columns = self::importer($type)::COLUMNS;

        return response()->streamDownload(function () use ($columns) {
            echo implode(';', $columns)."\r\n";
        }, 'modelo-'.str_replace('_', '-', $type).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Types this user may import: the catalog ones only for who keeps the network catalog (step 4b.1b).
     *
     * @return array<string, class-string>
     */
    private static function types(Request $request): array
    {
        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);

        return [
            ...(ImportBatchPolicy::importableUnitIds($membership) !== [] ? ['professionals' => ProfessionalCsvImporter::class, 'shifts' => ShiftCsvImporter::class] : []),
            ...($request->user()->can('manage', Medication::class) ? ['medications' => MedicationCsvImporter::class, 'medication_interactions' => MedicationInteractionCsvImporter::class] : []),
            // Step 5.1b: the exam catalog and its analytes, by who keeps the network catalog.
            ...($request->user()->can('manage', Exam::class) ? ['exams' => ExamCsvImporter::class, 'exam_analytes' => ExamAnalyteCsvImporter::class] : []),
            // Step 5b.1b: the surgical procedures, by who keeps the network catalog.
            ...($request->user()->can('manage', SurgicalProcedure::class) ? ['surgical_procedures' => SurgicalProcedureCsvImporter::class, 'materials' => MaterialCsvImporter::class] : []),
            // Step 4c.1b: the initial stock balance, by the unit's pharmacy.
            ...(PharmacyController::unitIds($membership) !== [] ? ['stock' => StockCsvImporter::class] : []),
        ];
    }

    /**
     * @return class-string<ProfessionalCsvImporter>|class-string<ShiftCsvImporter>|class-string<MedicationCsvImporter>|class-string<MedicationInteractionCsvImporter>|class-string<StockCsvImporter>|class-string<ExamCsvImporter>|class-string<ExamAnalyteCsvImporter>|class-string<SurgicalProcedureCsvImporter>|class-string<MaterialCsvImporter>
     */
    public static function importer(string $type): string
    {
        return match ($type) {
            'stock' => StockCsvImporter::class,
            'shifts' => ShiftCsvImporter::class,
            'medications' => MedicationCsvImporter::class,
            'medication_interactions' => MedicationInteractionCsvImporter::class,
            'exams' => ExamCsvImporter::class,
            'exam_analytes' => ExamAnalyteCsvImporter::class,
            'surgical_procedures' => SurgicalProcedureCsvImporter::class,
            'materials' => MaterialCsvImporter::class,
            default => ProfessionalCsvImporter::class,
        };
    }

    private const CATALOG = ['medications', 'medication_interactions'];
}
