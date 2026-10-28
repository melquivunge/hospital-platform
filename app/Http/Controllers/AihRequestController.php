<?php

namespace App\Http\Controllers;

use App\Billing\SusRules;
use App\Clinical\Cid10;
use App\Clinical\ClinicalAccess;
use App\Clinical\PatientIdentity;
use App\Models\Admission;
use App\Models\AihRequest;
use App\Models\AuditEvent;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use stdClass;

/**
 * Step 6b.5a (ADR-0011): the AIH request report of a SUS admission. The responsible doctor signs it with the password:
 * the requested SIGTAP procedure (registered as the AIH's principal procedure in the latest imported competence), the
 * CID-10 (compatible with the procedure) and the clinical justification. A correction is a new version. Printed for
 * the manager's authorization flow; billing never reads it (it records the AIH number, step 6b.5b).
 */
class AihRequestController extends Controller
{
    /** The SIGTAP instrument "AIH (Proc. Principal)". */
    private const AIH_PRINCIPAL = '03';

    public const LIST_LIMIT = 200;

    /** SIGTAP names are upper case with accents: compared without them. */
    private const ACCENTS = ['ÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇ', 'AAAAAEEEEIIIIOOOOOUUUUC'];

    public function store(Request $request, Admission $admission): RedirectResponse
    {
        $professional = ClinicalAccess::professional($request->user()) ?? abort(403);
        abort_unless($admission->responsible_professional_id === $professional->id && Diagnosis::requiredForAdmission($admission), 403);
        $data = $request->validate([
            'procedure_code' => ['required', 'string', 'max:20'],
            'justification' => ['required', 'string', 'max:4000'],
            'password' => ['required', 'string'],
        ], [], ['procedure_code' => 'procedimento solicitado', 'justification' => 'justificativa', 'password' => 'senha']);
        $timezone = $admission->unit()->value('timezone') ?? 'UTC';
        $procedure = self::procedure($admission->tenant_id, (string) preg_replace('/\D/', '', $data['procedure_code']), $timezone);
        if ($procedure === null) {
            throw ValidationException::withMessages(['procedure_code' => 'Procedimento não encontrado na SIGTAP como principal da AIH (use o código de 10 dígitos).']);
        }
        $patient = Patient::query()->findOrFail($admission->patient_id);
        [$cid, $secondary] = Diagnosis::fromRequest($request, true, $patient->sex) ?? [null, []];
        if (count($secondary) > 1) {
            throw ValidationException::withMessages(['cid_secondary' => 'O laudo da AIH leva um CID secundário.']);
        }
        $cids = SusRules::list($procedure->cids);
        if ($cids !== [] && ! in_array($cid, $cids, true)) {
            throw ValidationException::withMessages(['cid_principal' => "O CID {$cid} não é compatível com {$procedure->code} na SIGTAP."]);
        }
        VisitController::confirmPassword($request, $data['password'], 'aih_request.sign_failed', $admission, 'Senha incorreta. O laudo não foi assinado.');

        $id = DB::transaction(function () use ($admission, $professional, $procedure, $cid, $secondary, $data, $request) {
            $locked = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, Admission::ACTIVE, true) || $locked->responsible_professional_id !== $professional->id) {
                throw ValidationException::withMessages(['procedure_code' => 'A internação mudou nesse meio-tempo. Recarregue a página.']);
            }
            $version = (int) DB::table('aih_requests')->where('admission_id', $locked->id)->max('version') + 1;

            return DB::table('aih_requests')->insertGetId([
                'tenant_id' => $locked->tenant_id, 'unit_id' => $locked->unit_id, 'patient_id' => $locked->patient_id, 'admission_id' => $locked->id,
                'version' => $version, 'procedure_code' => $procedure->code, 'procedure_name' => $procedure->name, 'cid_code' => $cid,
                'cid_secondary' => $secondary[0] ?? null, 'justification' => $data['justification'], 'author_user_id' => $request->user()->id,
                'professional_id' => $professional->id, 'signed_at' => now(),
            ]);
        });
        // Ids only: the procedure tied to the admission is clinical, like the CID and the justification (ADR-0011).
        AuditEvent::record('aih_request.signed', $admission->tenant_id, $admission, ['aih_request_id' => $id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Laudo da AIH assinado. Imprima e envie para a autorização.']);

        return to_route('ward.show', $admission);
    }

    /**
     * Step 6b.5a2 (user's decision, 28/10/2026): the reports outlive the admission. The auditor lists the network's,
     * a doctor the ones they signed; the list carries no clinical content (no procedure, CID nor justification).
     */
    public function index(Request $request): InertiaResponse
    {
        $auditor = self::auditor($request);
        $professional = ClinicalAccess::professional($request->user());
        abort_unless($auditor || $professional !== null, 403);
        Inertia::encryptHistory();
        $units = Unit::query()->get(['id', 'name', 'timezone'])->keyBy('id');

        // ponytail: the newest LIST_LIMIT reports (the page says when it is cut); add filters and paging when volumes need them.
        return Inertia::render('aih-requests/index', [
            'auditor' => $auditor,
            'limit' => self::LIST_LIMIT,
            'reports' => AihRequest::query()->when(! $auditor, fn ($query) => $query->where('aih_requests.professional_id', $professional?->id))
                ->join('patients', 'patients.id', '=', 'aih_requests.patient_id')->join('professionals', 'professionals.id', '=', 'aih_requests.professional_id')
                ->orderByDesc('aih_requests.signed_at')->orderByDesc('aih_requests.id')->limit(self::LIST_LIMIT)
                ->get(['aih_requests.id', 'aih_requests.unit_id', 'aih_requests.admission_id', 'aih_requests.version', 'aih_requests.signed_at',
                    'patients.name', 'patients.social_name', 'patients.cpf', 'professionals.name as signer'])
                ->map(fn (AihRequest $report) => [
                    'id' => $report->id,
                    'admission' => $report->admission_id,
                    'version' => $report->version,
                    'patient' => trim(($report->getAttribute('social_name') ?? $report->getAttribute('name')).' '.(PatientIdentity::maskCpf($report->getAttribute('cpf')) ?? '')),
                    'unit' => $units->get($report->unit_id)?->name,
                    'signer' => $report->getAttribute('signer'),
                    'signedAt' => $report->signed_at->setTimezone($units->get($report->unit_id)->timezone ?? 'UTC')->format('d/m/Y H:i'),
                ]),
        ]);
    }

    /**
     * A report as a PDF: for whoever attends the admission while it lasts, for the doctor who signed it (also after
     * the discharge) and for the auditor (user's decision, 28/10/2026), each read in the trail.
     */
    public function pdf(Request $request, AihRequest $report): Response
    {
        $patient = Patient::query()->findOrFail($report->patient_id);
        $admission = Admission::query()->findOrFail($report->admission_id);
        $basis = match (true) {
            ClinicalAccess::activeAdmission($request->user(), $patient)?->id === $admission->id => $admission,
            ClinicalAccess::professional($request->user())?->id === $report->professional_id => $admission,
            self::auditor($request) => $report,
            default => abort(403),
        };
        ClinicalAccess::recordRead($request->user(), $patient, $basis);
        AuditEvent::record('aih_request.printed', $report->tenant_id, $admission, ['aih_request_id' => $report->id, 'reason' => $basis instanceof AihRequest ? 'audit' : 'admission']);

        $unit = Unit::query()->with('tenant')->findOrFail($report->unit_id);
        $professional = Professional::query()->whereKey($report->professional_id)->firstOrFail();
        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']));
        $pdf->loadHtml(view('aih.request', [
            'network' => $unit->tenant->name, 'unit' => $unit->name, 'cnes' => $unit->cnes, 'version' => $report->version,
            'patient' => $patient, 'sex' => ['female' => 'Feminino', 'male' => 'Masculino'][$patient->sex] ?? null,
            'procedure' => "{$report->procedure_code} · {$report->procedure_name}",
            'cid' => $report->cid_code.' · '.Cid10::name($report->cid_code),
            'cidSecondary' => $report->cid_secondary === null ? null : $report->cid_secondary.' · '.Cid10::name($report->cid_secondary),
            'justification' => $report->justification,
            'professional' => $professional->name, 'cns' => $professional->cns,
            'council' => $professional->council === null ? null : "{$professional->council} {$professional->council_number}/{$professional->council_uf}",
            'signedAt' => $report->signed_at->setTimezone($unit->timezone ?? 'UTC')->format('d/m/Y H:i'),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"laudo-aih-{$report->admission_id}-v{$report->version}.pdf\"",
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * The procedure search of the report's field and of billing's change of the principal (step 6b.5b2): the network's
     * catalog, AIH principal procedures only.
     */
    public function search(Request $request): JsonResponse
    {
        abort_unless(ClinicalAccess::professional($request->user()) !== null || BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())) !== [], 403);
        // The network's time, as on the SIGTAP screen: its first unit's zone.
        $competence = self::competence((int) UnitPolicy::currentMembership($request->user())?->tenant_id, Unit::query()->orderBy('id')->value('timezone') ?? 'UTC');
        $typed = $request->query('q');
        $term = is_string($typed) ? trim(mb_substr($typed, 0, 60)) : '';
        if ($competence === null || mb_strlen($term) < 2) {
            return response()->json([]);
        }
        $query = DB::table('sigtap_procedures')->where('sigtap_competence_id', $competence)->whereRaw('? = ANY(registers)', [self::AIH_PRINCIPAL]);
        // A code typed with its dots and dash (03.03.14.015-1) is a code too.
        if (preg_match('/^[\d.\-\s]+$/', $term) === 1) {
            $query->where('code', 'like', preg_replace('/\D/', '', $term).'%');
        } else {
            foreach (preg_split('/\s+/', strtoupper(Str::ascii($term))) ?: [] as $word) {
                $query->whereRaw('translate(upper(name), ?, ?) LIKE ?', [...self::ACCENTS, '%'.addcslashes($word, '%_\\').'%']);
            }
        }

        return response()->json($query->orderBy('code')->limit(20)->get(['code', 'name']));
    }

    /**
     * What the ward page shows of the reports: the latest one, for whoever attends; who may sign one.
     *
     * @return array<string, mixed>|null
     */
    public static function panel(Admission $admission, ?Professional $professional, string $timezone): ?array
    {
        if (! Diagnosis::requiredForAdmission($admission)) {
            return null;
        }
        $report = DB::table('aih_requests')->where('admission_id', $admission->id)->orderByDesc('version')->first();

        return [
            'current' => $report === null ? null : [
                'id' => (int) $report->id,
                'version' => (int) $report->version,
                'procedure' => "{$report->procedure_code} · {$report->procedure_name}",
                'cid' => $report->cid_code,
                'signedAt' => CarbonImmutable::parse($report->signed_at)->setTimezone($timezone)->format('d/m/Y H:i'),
                'by' => Professional::query()->whereKey($report->professional_id)->value('name'),
            ],
            'canSign' => $professional !== null && $admission->responsible_professional_id === $professional->id && in_array($admission->status, Admission::ACTIVE, true),
            'sigtap' => self::competence($admission->tenant_id, $timezone) !== null,
        ];
    }

    /** The auditor of the network in session (a network-wide role). */
    public static function auditor(Request $request): bool
    {
        return UnitPolicy::currentMembership($request->user())?->hasRole(Role::Auditor) ?? false;
    }

    /** The network's latest imported SIGTAP competence up to this month, in the given zone (signed on any day). */
    private static function competence(int $tenantId, string $timezone): ?int
    {
        $id = DB::table('sigtap_competences')->where('tenant_id', $tenantId)->where('competence', '<=', now($timezone)->startOfMonth()->toDateString())->orderByDesc('competence')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** The procedure's row (code, name, cids). */
    private static function procedure(int $tenantId, string $code, string $timezone): ?stdClass
    {
        $competence = self::competence($tenantId, $timezone);

        return $competence === null || preg_match('/^\d{10}$/', $code) !== 1 ? null : DB::table('sigtap_procedures')->where('sigtap_competence_id', $competence)
            ->where('code', $code)->whereRaw('? = ANY(registers)', [self::AIH_PRINCIPAL])->first(['code', 'name', 'cids']);
    }
}
