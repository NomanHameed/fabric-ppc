<?php

namespace App\Modules\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limits self-registration.
 *
 * Fortify throttles login, the two-factor challenge and passkeys, but it puts **no** limiter on
 * `POST /register` — its own route carries `guest:web` and nothing else. Registration is open on this project
 * (see RegistrationTest), and every accepted post creates a user row, queues a verification mail and writes a
 * `user_actions` row, so one script can fill the approval queue, the action log and the mail spool from a
 * single address.
 *
 * Why a middleware in the `web` group rather than `throttle:register` on the route: the route belongs to
 * Fortify's package route file, which this application does not own and must not edit. Appending the limiter
 * here is independent of provider boot order and survives `route:cache`, and it still reads the limiter name
 * from `config('fortify.limiters.register')`, so the configuration stays in one place beside the other three.
 *
 * The work is delegated to Laravel's own ThrottleRequests, so the 429, the `Retry-After` and the
 * `X-RateLimit-*` headers are exactly what every other throttled route returns.
 */
class ThrottleRegistrations
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limiter = config('fortify.limiters.register');

        if (! $request->isMethod('POST') || ! $request->routeIs('register.store') || ! is_string($limiter) || $limiter === '') {
            return $next($request);
        }

        // Exactly three arguments: ThrottleRequests treats a string third argument as a named limiter only
        // when func_num_args() === 3.
        return $this->throttle->handle($request, $next, $limiter);
    }
}
