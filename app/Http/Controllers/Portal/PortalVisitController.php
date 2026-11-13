<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\VisitPass;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7b.1: the journey of one arrival, opened by the QR without an account. No name, no results: only the unit and
 * where the arrival is now. An unknown, revoked or expired QR gets the same answer.
 */
class PortalVisitController extends Controller
{
    public function __invoke(string $portal, string $token): Response
    {
        $pass = VisitPass::forToken($token);
        $valid = $pass !== null && ! $pass->expired();

        return Inertia::render('portal/visit', [
            'network' => PortalHomeController::network(),
            'visit' => $valid ? [
                'unit' => Unit::query()->whereKey($pass->unit_id)->value('name'),
                ...collect($pass->journey())->except('endedAt')->all(),
            ] : null,
        ]);
    }
}
