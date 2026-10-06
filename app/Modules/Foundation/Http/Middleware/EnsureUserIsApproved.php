<?php

namespace App\Modules\Foundation\Http\Middleware;

use App\Modules\Foundation\Access\Approval;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signup approval boundary. A logged-in user whose account is not `approved` gets nothing: no screen, no
 * data, no JSON — whatever route they type.
 *
 * This runs in the `web` group for every request, not on a route group, so a route that forgets its middleware
 * is still covered. The allow-list is closed and deliberately tiny: the account has to be able to read the
 * "waiting for approval" screen, act on the emailed verification link, and log out. Everything else —
 * including every module route, every LOV, every export — is refused.
 *
 * Nothing on the list renders the application shell. `verification.notice` used to be allowed, and Fortify
 * renders it with `Inertia::render`, which meant an unapproved account was handed the shell, its shared props
 * (its own row, its roles, the whole permission list) and the JavaScript bundle. It is off the list: the
 * pending screen already tells the account whether its address is confirmed, and `verification.send` and
 * `verification.verify` stay allowed so the resend button and the emailed link still work. An approved but
 * unverified account never reaches this middleware's body at all, so Fortify's own verify screen is unchanged
 * for them.
 *
 * Guests fall through untouched; authentication itself is Fortify's job, and EnsureUserIsActive (which runs
 * first) handles disabled accounts.
 */
class EnsureUserIsApproved
{
    /**
     * Route names an unapproved account may still reach.
     *
     * @var list<string>
     */
    public const ALLOWED_ROUTES = [
        'approval.pending',     // the "waiting for approval" screen itself
        'logout',
        'verification.verify',  // the emailed link: a pending user may still confirm their address
        'verification.send',    // the resend button on the pending screen
        'i18n.show',            // translations for the pending screen (public route anyway)
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->approval_status === Approval::APPROVED) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        // An Inertia XHR asks for text/html, so a plain 302 would be followed by the client and answered with
        // a full HTML document it cannot parse. This is reachable: a session left open while a moderator
        // rejects the account. `Inertia::location` is the 409 the client understands as "hard-navigate here".
        // It is checked before expectsJson(), which is true for Inertia too (it sends X-Requested-With).
        if ($request->hasHeader('X-Inertia')) {
            return Inertia::location(route('approval.pending'));
        }

        if ($request->expectsJson()) {
            abort(403, __('approval.blocked'));
        }

        return redirect()->route('approval.pending');
    }
}
