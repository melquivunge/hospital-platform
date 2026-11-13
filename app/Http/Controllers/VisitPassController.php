<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\VisitPass;
use App\Policies\UnitPolicy;
use App\Tenancy\CurrentTenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7b.1 (contract, decision 4): the reception hands the patient a QR on arrival. The token is shown once, right
 * after it is made; a new QR for the same arrival revokes the previous one.
 */
class VisitPassController extends Controller
{
    public function storeForAppointment(Request $request, Appointment $appointment): RedirectResponse
    {
        self::authorizeUnit($request, $appointment->unit_id);

        if (! in_array($appointment->status, ['arrived', 'in_progress'], true)) {
            throw ValidationException::withMessages(['pass' => 'Confirme a chegada antes de gerar o QR.']);
        }

        return self::issue($request, $appointment->unit_id, ['appointment_id' => $appointment->id]);
    }

    public function storeForEpisode(Request $request, EmergencyEpisode $episode): RedirectResponse
    {
        self::authorizeUnit($request, $episode->unit_id);

        if ($episode->ended_at !== null) {
            throw ValidationException::withMessages(['pass' => 'Este atendimento já terminou.']);
        }

        return self::issue($request, $episode->unit_id, ['emergency_episode_id' => $episode->id]);
    }

    public function show(Request $request, VisitPass $pass): Response
    {
        self::authorizeUnit($request, $pass->unit_id);
        $token = $request->session()->get('visit_pass_token');
        // The token exists only in this one answer: the page cannot show it again.
        $fresh = is_array($token) && ($token['id'] ?? null) === $pass->id && $pass->revoked_at === null ? (string) $token['token'] : null;
        $url = $fresh === null ? null : self::url($fresh);

        return Inertia::render('visit-passes/show', [
            'pass' => ['id' => $pass->id, 'revoked' => $pass->revoked_at !== null, 'createdAt' => $pass->created_at->toIso8601String()],
            'qr' => $url === null ? null : self::qr($url),
            'url' => $url,
            'back' => $pass->appointment_id !== null ? route('appointments.queue') : route('emergency.index'),
        ]);
    }

    public function revoke(Request $request, VisitPass $pass): RedirectResponse
    {
        self::authorizeUnit($request, $pass->unit_id);

        DB::transaction(function () use ($request, $pass) {
            $locked = VisitPass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->revoked_at !== null, 404);
            $locked->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $request->user()?->id])->save();
            AuditEvent::record('visit_pass.revoked', $locked->tenant_id, $locked);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'QR revogado. Ele deixa de abrir a jornada.']);

        return back();
    }

    /**
     * @param  array{appointment_id?: int, emergency_episode_id?: int}  $origin
     */
    private static function issue(Request $request, int $unitId, array $origin): RedirectResponse
    {
        $token = Str::random(48);

        $pass = DB::transaction(function () use ($request, $unitId, $origin, $token) {
            // One live QR per arrival: a new one replaces the one before.
            VisitPass::query()->where($origin)->whereNull('revoked_at')->lockForUpdate()->get()
                ->each(fn (VisitPass $old) => $old->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $request->user()?->id])->save());

            $pass = new VisitPass;
            $pass->forceFill([
                'tenant_id' => app(CurrentTenant::class)->require()->id, 'unit_id' => $unitId, ...$origin,
                'token_hash' => hash('sha256', $token), 'created_by_user_id' => $request->user()?->id,
            ])->save();
            AuditEvent::record('visit_pass.created', $pass->tenant_id, $pass);

            return $pass;
        });

        return to_route('visit-passes.show', $pass)->with('visit_pass_token', ['id' => $pass->id, 'token' => $token]);
    }

    private static function url(string $token): string
    {
        // Never the request's Host: a forged one would print someone else's address on the patient's QR.
        return rtrim((string) config('app.url'), '/').route('portal.visit', ['portal' => app(CurrentTenant::class)->require()->slug, 'token' => $token], false);
    }

    private static function qr(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd)))->writeString($url);

        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }

    private static function authorizeUnit(Request $request, int $unitId): void
    {
        abort_unless(in_array($unitId, AppointmentController::bookingUnitIds(UnitPolicy::currentMembership($request->user())), true), 403);
    }
}
