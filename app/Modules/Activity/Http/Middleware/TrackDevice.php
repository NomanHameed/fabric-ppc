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
        // A refused account still gets its cookie — the terminal keeps one identity, and the row it belongs to
        // is created the moment the account is approved — but it must not be able to write rows.
        //
        // This middleware runs before EnsureUserIsApproved (deliberately: a blocked attempt should still be
        // attributable), and that gate redirects without logging the session out. So a rejected user who
        // varies the `device_id` cookie was minting one user_devices row per request, unbounded, from a
        // session the application has already refused. Only an approved account gets a row.
        $this->devices->track($request, rows: $request->user()?->isApproved() ?? true);

        return $next($request);
    }
}
