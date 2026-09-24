<?php

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
// Concurrency: two real connections need committed rows, so no wrapping transaction (MySQL only; skipped on SQLite)
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Concurrency');
// Browser: Pest's browser plugin boots its own in-process HTTP server, so the test transaction is shared.
// Skipped (never failed) when Playwright or Chromium is missing, so the gate stays green without a browser.
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
 * Why browser tests cannot run here, or null when Playwright and Chromium are both installed.
 *
 * Kept cheap (filesystem only): it runs before every browser test.
 */
function browserUnavailableReason(): ?string
{
    static $reason = false;

    if ($reason !== false) {
        // @phpstan-ignore-next-line return.type
        return $reason;
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

const ORACLE_HEADERS = [
    'PARTY', 'PARTY_CODE', 'CONTRACT_NUM', 'REF_NUM', 'DATED', 'QUALITY', 'Q_CODE', 'LOT_NUM1', 'GSM', 'DESIGN_CODE',
    'DESIGN', 'COLOUR_NAME', 'COLOUR_CODE', 'G_WIDTH', 'F_WIDTH', 'PROCESS_TYPE', 'PC_DTL_ID', 'QNTY', 'QNTY_CONT',
];

/** @param list<array> $dataRows each row values in ORACLE_HEADERS order */
function buildOracleXlsx(array $dataRows): string
{
    $path = tempnam(sys_get_temp_dir(), 'orc').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(ORACLE_HEADERS));
    foreach ($dataRows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return $path;
}

/** A minimal, valid data row for the 19 required headers, keyed by the fields callers usually vary. */
function oracleRow(array $overrides = []): array
{
    $defaults = [
        'PARTY' => 'NORMAL PARTY', 'PARTY_CODE' => 1, 'CONTRACT_NUM' => 700001, 'REF_NUM' => 'R', 'DATED' => '01-SEP-26',
        'QUALITY' => 'Q', 'Q_CODE' => 10, 'LOT_NUM1' => 700001, 'GSM' => '', 'DESIGN_CODE' => '', 'DESIGN' => '',
        'COLOUR_NAME' => '', 'COLOUR_CODE' => '', 'G_WIDTH' => 60, 'F_WIDTH' => 55, 'PROCESS_TYPE' => 'DYED',
        'PC_DTL_ID' => 9000, 'QNTY' => 100, 'QNTY_CONT' => 100,
    ];
    $row = array_merge($defaults, $overrides);

    return array_map(fn ($h) => $row[$h], ORACLE_HEADERS);
}
