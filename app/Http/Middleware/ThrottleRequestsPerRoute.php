<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:X,Y` counted per route (step 4b.6). Laravel's default key is the user (or the IP) alone, so every
 * throttled route of a person shared one counter: a nurse busy on other screens could be refused a dose record.
 */
class ThrottleRequestsPerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();

        return sha1(parent::resolveRequestSignature($request).'|'.($route?->getName() ?? $route?->uri()));
    }
}
