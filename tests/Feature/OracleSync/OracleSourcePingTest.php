<?php

use App\Modules\OracleSync\Source\Models\OracleColour;
use App\Modules\OracleSync\Source\OracleSourceConnection;
use App\Modules\OracleSync\Source\SourceModel;
use Database\Seeders\OracleMockSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `oracle:ping` is the read-only diagnostic for the Oracle source. The suite has no Oracle instance (and no
 * oci8), so these run it against the stand-in — which is exactly what makes them a regression guard for the
 * command's own plumbing: table discovery, per-table error handling, exit codes, and the promise that it
 * never writes.
 */

/** @return array<string, string> table name => model class, taken from the models the command also uses. */
function pingSourceTables(): array
{
    $tables = [];

    foreach ((array) glob(app_path('Modules/OracleSync/Source/Models/*.php')) as $file) {
        $class = 'App\\Modules\\OracleSync\\Source\\Models\\'.basename((string) $file, '.php');
        if (class_exists($class) && is_subclass_of($class, SourceModel::class)) {
            $tables[(new $class)->getTable()] = $class;
        }
    }

    return $tables;
}

it('reports every source table against the stand-in', function () {
    $tables = pingSourceTables();
    // Twelve distinct source objects: six in PRODUCTION, six in ERP. There is no MACHINE_M — machines are
    // rows in ERP.COST_CENTRES, which is why CostCentre is the only model over that table (see its docblock
    // on why a second one would collide in this very map).
    expect($tables)->toHaveCount(12);

    $exit = Artisan::call('oracle:ping');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain(count($tables).' of '.count($tables).' source table(s) readable.')
        ->and($output)->toContain('local stand-in');

    foreach (array_keys($tables) as $table) {
        expect($output)->toContain($table);
    }
});

it('prints the effective settings but never the password', function () {
    config(['database.connections.'.OracleSourceConnection::NAME.'.password' => 'not-in-the-output']);

    Artisan::call('oracle:ping');
    $output = Artisan::output();

    expect($output)->toContain('Driver')
        ->and($output)->toContain(OracleSourceConnection::driver())
        ->and($output)->toContain('(set — not shown)')
        ->and($output)->not->toContain('not-in-the-output');
});

it('still runs with --tables-only', function () {
    $this->artisan('oracle:ping', ['--tables-only' => true])->assertExitCode(0);
});

it('reports a missing table without giving up on the others, and fails', function () {
    $tables = pingSourceTables();
    $oracle = Schema::connection(OracleSourceConnection::NAME);

    // Renamed rather than dropped: the stand-in is not inside the test transaction, so the table (and its
    // rows) have to come back exactly as they were for the rest of the suite.
    $oracle->rename('COLOURS', 'COLOURS_PING_TEST');

    try {
        $exit = Artisan::call('oracle:ping');
        $output = Artisan::output();
    } finally {
        $oracle->rename('COLOURS_PING_TEST', 'COLOURS');
    }

    expect($exit)->toBe(1)
        ->and($output)->toContain((count($tables) - 1).' of '.count($tables).' source table(s) readable.')
        ->and($output)->toContain('COLOURS');

    // Every other table was still checked: the first failure does not abort the run.
    foreach (array_keys($tables) as $table) {
        expect($output)->toContain($table);
    }
});

it('does not write to the source', function () {
    $this->seed(OracleMockSeeder::class);

    $before = [];
    foreach (pingSourceTables() as $table => $class) {
        $before[$table] = DB::connection(OracleSourceConnection::NAME)->table($table)->count();
    }
    expect(array_sum($before))->toBeGreaterThan(0);

    expect(Artisan::call('oracle:ping'))->toBe(0);

    foreach (pingSourceTables() as $table => $class) {
        expect(DB::connection(OracleSourceConnection::NAME)->table($table)->count())
            ->toBe($before[$table], "{$table} is unchanged");
    }

    // And the boundary that makes that structural rather than incidental (also asserted in OracleSourceTest).
    expect(fn () => OracleColour::query()->firstOrFail()->update([OracleColour::NAME_COLUMN => 'x']))
        ->toThrow(LogicException::class);
});
