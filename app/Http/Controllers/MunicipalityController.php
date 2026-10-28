<?php

namespace App\Http\Controllers;

use App\Clinical\Municipality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Step 6b.6b1: the IBGE municipality search of the address fields (the patient's, the unit's): the public table.
 */
class MunicipalityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $typed = $request->query('q');

        return response()->json(Municipality::search(is_string($typed) ? mb_substr($typed, 0, 60) : ''));
    }
}
