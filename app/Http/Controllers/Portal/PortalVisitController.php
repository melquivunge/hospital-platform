<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\VisitPass;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 7b.1: the journey of one arrival, opened by the QR without an account. No name, no results: only the unit and
 * where the arrival is now. An unknown, revoked or expired QR gets the same answer.
 */
class PortalVisitController extends Controller
{
    public function __invoke(Request $request, string $portal, string $token): Response
    {
        $pass = VisitPass::forToken($token);
        $journey = $pass?->journey();
        $valid = $pass !== null && $journey !== null && ! VisitPass::expiredAt($journey['endedAt']);

        $response = Inertia::render('portal/visit', [
            'network' => PortalHomeController::network(),
            'visit' => $valid ? PortalJourney::present($journey) : null,
        ])->toResponse($request);
        // The address holds the token: never cached, never sent on as Referer.
        $response->headers->add(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);

        return $response;
    }
}
