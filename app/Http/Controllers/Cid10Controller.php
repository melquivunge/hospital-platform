<?php

namespace App\Http\Controllers;

use App\Clinical\Cid10;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Step 6b.2 (ADR-0011): the CID-10 search for the code fields (closing a visit, the discharge): the public table, no
 * patient data.
 */
class Cid10Controller extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(Cid10::search(mb_substr((string) $request->query('q'), 0, 60)));
    }
}
