<?php

namespace App\Modules\Activity\Services;

use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Models\UserDevice;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records a business action in one call. Who, what, which record, from where — everything except the action
 * key and its replacements is taken from the current request, so a caller can never forget the device or IP.
 *
 * The description is rendered from `activity.actions.<key>` in the *fallback* locale and stored, so the log
 * reads identically for everyone later no matter who was looking at an Urdu screen when it happened;
 * UserAction::label() re-renders it in the reader's locale when a key still exists.
 *
 * Nothing here writes lot quantities. Recording that a lot moved does not move it — LedgerService does that,
 * and remains the only writer of lot_movements and lot_balances.
 *
 * Not final only so `withoutRealRequest()` can be overridden in a test; nothing in the application extends it.
 */
class ActionLogger
{
    public function __construct(
        private readonly Container $container,
        private readonly DeviceRegistrar $devices,
    ) {}

    /**
     * @param  string  $action  an ActionKeys constant
     * @param  Model|null  $subject  the record acted on
     * @param  array<string, scalar|null>  $context  replacements for the description, kept on the row
     * @param  CarbonInterface|null  $at  when it happened, when that is not "now": a production sheet or an
     *                                    issue is entered for a production date, sometimes days later, and the
     *                                    log should read in the order the mill worked, not the order it typed
     */
    public function log(string $action, ?Model $subject = null, array $context = [], ?User $user = null, ?CarbonInterface $at = null): UserAction
    {
        $request = $this->request();
        $actor = $user ?? Auth::user();

        return $this->record(
            $action,
            $subject,
            $context,
            $actor,
            $this->device($request, $actor),
            $request?->ip(),
            $request === null ? null : (string) $request->userAgent(),
            $at,
        );
    }

    /**
     * The terminal to stamp on the row.
     *
     * TrackDevice does not create a device row for an anonymous request (an unauthenticated crawler would
     * otherwise mint one per visit), so the first action a brand new account records — the signup itself —
     * arrives with nothing resolved. Recording an action is proof of a real person at a real terminal, so this
     * is the point at which the row is worth creating.
     *
     * Only when TrackDevice actually ran, though: a seeder has a request object in the container but no
     * terminal behind it, and must not conjure one.
     */
    private function device(?Request $request, ?User $actor): ?UserDevice
    {
        $current = $this->devices->current();

        if ($current !== null || $request === null || $actor === null || ! $this->devices->wasTracked()) {
            return $current;
        }

        return $this->devices->resolve($request);
    }

    /**
     * The full form: used by log() with everything taken from the request, and by the demo seeders, which have
     * no request but do have a device, an address and a date.
     *
     * @param  array<string, scalar|null>  $context
     */
    public function record(
        string $action,
        ?Model $subject,
        array $context,
        ?User $user,
        ?UserDevice $device,
        ?string $ip,
        ?string $userAgent,
        ?CarbonInterface $at,
    ): UserAction {
        $attributes = [
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $this->describe($action, $context),
            'context' => $context === [] ? null : $context,
            'ip_address' => $ip,
            'user_device_id' => $device?->getKey(),
            'device_id' => $device?->device_id,
            'computer_name' => $device?->computer_name,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 250, ''),
            'occurred_at' => $at ?? now(),
        ];

        // The log must never be the thing that fails the request. Recording is the *last* step of an operation
        // that has already happened and already committed — a lot really was issued, an account really was
        // approved — so a failure here (a column too short, the table locked, the connection gone) must not
        // turn a completed business action into a 500 the user reads as "it did not work". It is logged as a
        // warning and the caller gets a non-persisted instance, which has the same shape and an empty key.
        //
        // This is why every caller records *after* its transaction commits: swallowing an exception raised
        // inside someone else's transaction would hide a rollback rather than a lost log line.
        try {
            return UserAction::query()->create($attributes);
        } catch (Throwable $e) {
            Log::warning('Failed to record a user action; the action itself was not affected.', [
                'action' => $action,
                'subject_type' => $attributes['subject_type'],
                'subject_id' => $attributes['subject_id'],
                'user_id' => $attributes['user_id'],
                'exception' => $e,
            ]);

            return new UserAction($attributes);
        }
    }

    /**
     * The stored, locale-stable sentence. Falls back to the bare key only when a lang line is missing, which
     * the ActionKeys parity test exists to prevent.
     *
     * @param  array<string, scalar|null>  $context
     */
    private function describe(string $action, array $context): string
    {
        $key = 'activity.actions.'.$action;
        $fallback = (string) config('app.fallback_locale', 'en');
        $rendered = trans($key, $context, $fallback);

        return Str::limit(is_string($rendered) ? $rendered : $key, 250, '');
    }

    /**
     * The request behind this action, or null when there is not one.
     *
     * `bound('request')` is not the test: `request` is a core container alias and is always bound, and
     * SetRequestForConsole hands artisan and the queue worker a request synthesised from `app.url` whose
     * `ip()` is 127.0.0.1 and whose agent is "Symfony". Stamping those on a console-driven action would be an
     * attribution log inventing an address, which is worse than recording none. Tests run under the console
     * SAPI but do issue real requests, so they are excluded from that check.
     */
    private function request(): ?Request
    {
        if ($this->withoutRealRequest()) {
            return null;
        }

        return $this->container->resolved('request') ? $this->container->make('request') : null;
    }

    /**
     * Whether this process has no real HTTP request behind it. A seam rather than an inline condition: the
     * class is otherwise untestable on this branch, because the test suite itself runs under the CLI SAPI.
     */
    protected function withoutRealRequest(): bool
    {
        $app = $this->container;

        return $app instanceof Application && $app->runningInConsole() && ! $app->runningUnitTests();
    }
}
