<?php

/*
 * The browser suite lives outside `tests/` on purpose.
 *
 * The Pest browser plugin starts a Playwright `run-server` node process as soon as it **collects** a test
 * that calls `visit()` — during collection, before any `beforeEach` hook can skip it, and whatever the
 * `--testsuite` or group filters say. On a machine where that server cannot reach a browser it is then left
 * behind, still holding the run's inherited stdout: the tests themselves finish in three minutes and a piped
 * `./vendor/bin/pest | tail` sits there for another five to fifteen before it gives up.
 *
 * Pest only scans its test directory (`--test-directory`, default `tests`), so keeping these files out of it
 * is the one thing that reliably stops the server ever starting. A bare `./vendor/bin/pest` never sees them
 * and always returns.
 *
 * Run them deliberately:
 *
 *     composer test:browser
 *     PEST_BROWSER=1 ./vendor/bin/pest --test-directory=tests-browser
 *
 * The `Browser` subdirectory name is load-bearing: the plugin also identifies a browser test by
 * `<test-directory>/Browser/…` (Pest\Browser\Support\BrowserTestIdentifier).
 */

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

// Browser: Pest's browser plugin boots its own in-process HTTP server, so the test transaction is shared.
// Skipped (never failed) when the opt-in is missing, or when Playwright or Chromium is.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('browser')
    ->beforeEach(function (): void {
        $reason = browserUnavailableReason();

        if ($reason !== null) {
            test()->markTestSkipped($reason);
        }

        // A developer's Vite dev server writes public/hot, which would point the pages at
        // http://127.0.0.1:5173 instead of the build the plugin serves from public/build.
        app(Vite::class)->useHotFile(storage_path('framework/testing/browser-tests-never-hot'));
    })
    ->in('Browser');

/**
 * Why browser tests cannot run here, or null when they have been asked for and can actually run.
 *
 * Kept cheap (filesystem only): it runs before every browser test.
 *
 * The `PEST_BROWSER` opt-in is belt to the directory move's braces: Playwright and Chromium can both be
 * installed and still be unusable (no sandbox, no display, a container), and a half-started browser run is
 * worse than a skipped one.
 */
function browserUnavailableReason(): ?string
{
    static $reason = false;

    if ($reason !== false) {
        // @phpstan-ignore-next-line return.type
        return $reason;
    }

    if (! filter_var(env('PEST_BROWSER', false), FILTER_VALIDATE_BOOLEAN)) {
        return $reason = 'Browser tests are opt-in — run: composer test:browser';
    }

    if (! file_exists(base_path('node_modules/.bin/playwright'))) {
        return $reason = 'Playwright is not installed — run: npm install -D playwright && npx playwright install chromium';
    }

    $browsersPath = env('PLAYWRIGHT_BROWSERS_PATH');

    if (! is_string($browsersPath) || $browsersPath === '') {
        $home = env('HOME');
        $browsersPath = is_string($home) && $home !== '' ? $home.'/.cache/ms-playwright' : '';
    }

    $chromium = $browsersPath === '' ? [] : glob($browsersPath.'/chromium*');

    if ($chromium === false || $chromium === []) {
        return $reason = 'Chromium is not installed for Playwright — run: npx playwright install chromium --with-deps';
    }

    // The plugin serves public/ straight from disk, so the pages need a production Vite build.
    if (! file_exists(public_path('build/manifest.json'))) {
        return $reason = 'The Vite build is missing — run: npm run build';
    }

    return $reason = null;
}

/**
 * Seeds the reference data a screen needs, creates a PPC user and authenticates it for the browser session.
 */
function browserLogin(string $role = Permissions::ROLE_PPC): User
{
    test()->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);

    /** @var User $user */
    $user = User::factory()->create(['email' => 'ppc@fabric.local', 'locale' => 'en']);
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}
