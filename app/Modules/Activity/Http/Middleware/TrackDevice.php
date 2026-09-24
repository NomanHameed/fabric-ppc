<?php

namespace App\Modules\Activity\Http\Middleware;

use App\Modules\Activity\Services\DeviceRegistrar;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues the device cookie on the first visit and keeps the device row current, so that every action logged
 * later in the request already knows which machine it came from.
 *
 * Runs on every web request including guests: a terminal gets its identity — the cookie — before anyone logs
 * in, which is what makes "who used this computer" answerable afterwards. It does not create a row for an
 * anonymous caller, though; see DeviceRegistrar::track().
 */
class TrackDevice
{
    public function __construct(private readonly DeviceRegistrar $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->devices->track($request);

        return $next($request);
    }
}
