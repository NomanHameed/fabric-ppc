<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Activity\Console\PruneUserActions;
use App\Modules\Activity\Http\Middleware\TrackDevice;
use App\Modules\Foundation\Http\Middleware\EnsureUserIsActive;
use App\Modules\Foundation\Http\Middleware\EnsureUserIsApproved;
use App\Modules\Foundation\Http\Middleware\SetLocale;
use App\Modules\Foundation\Http\Middleware\ThrottleRegistrations;
use App\Modules\Foundation\Support\ErrorReference;
use App\Modules\OracleSync\Console\ImportOracleFile;
use App\Modules\OracleSync\Console\PingOracleSource;
use App\Modules\Production\Console\BackfillLedger;
use App\Modules\Production\Console\SyncLotStages;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([ImportOracleFile::class, PingOracleSource::class, BackfillLedger::class, SyncLotStages::class, PruneUserActions::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            // First: a flood of signups is refused before anything writes a user, a mail or an action row.
            ThrottleRegistrations::class,
            EnsureUserIsActive::class,
            // Before the approval gate, so a blocked attempt is still attributable to a terminal.
            TrackDevice::class,
            // Signup approval is enforced here, in the group, not per route: a route that forgets its own
            // middleware is still refused. It runs before anything that could render or return data.
            EnsureUserIsApproved::class,
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $isExpected = fn (Throwable $e): bool => $e instanceof HttpExceptionInterface
            || $e instanceof HttpResponseException
            || $e instanceof ValidationException
            || $e instanceof AuthenticationException;

        // Every unexpected error gets a reference id the user can quote; the same id is in the log line (spec §9).
        // Laravel's default reporter keeps its context (userId, exception) and dedupe; only the ref is added.
        $exceptions->context(fn (Throwable $e) => ['ref' => ErrorReference::for($e)]);
        $exceptions->render(function (Throwable $e, Request $request) use ($isExpected) {
            if (config('app.debug') || $isExpected($e) || $request->expectsJson()) {
                return null;
            }

            return Inertia::render('Error', ['status' => 500, 'reference' => ErrorReference::for($e)])
                ->toResponse($request)->setStatusCode(500);
        });
    })->create();
