<?php

use App\Models\User;
use App\Modules\OracleSync\Source\Models\OracleColour;
use App\Modules\OracleSync\Source\OracleSchemaBrowser;
use App\Modules\OracleSync\Source\OracleSourceConnection;
use App\Modules\OracleSync\Source\OracleSourceDiagnostics;
use Database\Seeders\OracleMockSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

/**
 * The read-only Oracle source schema browser.
 *
 * The suite has no Oracle instance, so these run against the SQLite stand-in — which is the point: the
 * screen has to work on both, and this is where its plumbing (filters, paging, describe, the permission
 * gate, the graceful-failure path and above all the promise that it never writes) is pinned down.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->viewer = User::factory()->create()->assignRole('viewer');
});

/** @return list<string> the stand-in's table names, straight from the connection */
function standInTables(): array
{
    return array_values(array_map(
        fn (array $table): string => (string) $table['name'],
        app('db')->connection(OracleSourceConnection::NAME)->getSchemaBuilder()->getTables(),
    ));
}

it('renders the screen and lists the stand-in objects', function () {
    $tables = standInTables();
    expect($tables)->toContain('PROCESSING_LOTS', 'COLOURS', 'PARTIES');

    $this->actingAs($this->ppc)->get('/oracle/source')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('oracle/SourceBrowser')
            ->where('connection.mocked', true)
            ->where('error', null)
            ->where('selected', null)
            ->where('objects.total', count($tables))
            ->where('objects.rows', fn ($rows) => collect($rows)->pluck('name')->contains('PROCESSING_LOTS'))
            // Row counts stay null on a stand-in: there are no optimizer statistics, and a count(*) is
            // exactly what this screen must never do.
            ->where('objects.rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['rows'] === null))
            ->where('objects.rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['columns'] > 0))
            ->has('owners')
            ->where('types', OracleSchemaBrowser::TYPES));
});

it('defaults the owner filter to the connection schema and lets every owner through', function () {
    $this->actingAs($this->ppc)->get('/oracle/source')
        ->assertInertia(fn ($page) => $page->where('filters.owner', fn ($owner) => $owner !== null));

    // An explicitly empty owner means "every owner" — the other schemas are reachable, not hidden.
    $this->actingAs($this->ppc)->get('/oracle/source?owner=')
        ->assertInertia(fn ($page) => $page
            ->where('filters.owner', null)
            ->where('objects.total', count(standInTables())));
});

it('narrows the list by name, owner and type', function () {
    $this->actingAs($this->ppc)->get('/oracle/source?q=colour')
        ->assertInertia(fn ($page) => $page
            ->where('filters.q', 'colour')
            ->where('objects.total', 1)
            ->where('objects.rows.0.name', 'COLOURS'));

    // Case-insensitive, and a partial match.
    $this->actingAs($this->ppc)->get('/oracle/source?q=GREIGE_ISSUE')
        ->assertInertia(fn ($page) => $page->where('objects.total', 2));

    $this->actingAs($this->ppc)->get('/oracle/source?owner=NO_SUCH_SCHEMA')
        ->assertInertia(fn ($page) => $page->where('objects.total', 0)->where('objects.rows', []));

    $this->actingAs($this->ppc)->get('/oracle/source?type=TABLE')
        ->assertInertia(fn ($page) => $page
            ->where('objects.total', count(standInTables()))
            ->where('objects.rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['type'] === 'TABLE')));
});

it('lists views separately from tables', function () {
    // A view on the stand-in, so the type filter is exercised against something real. This is a write to
    // the local stand-in and nothing else: assertMocked() refuses it the moment oracle_src is real Oracle.
    OracleSourceConnection::assertMocked();
    $source = DB::connection(OracleSourceConnection::NAME);
    $source->statement('create view COLOUR_V as select * from COLOURS');

    try {
        $this->actingAs($this->ppc)->get('/oracle/source?type=VIEW')
            ->assertInertia(fn ($page) => $page
                ->where('objects.total', 1)
                ->where('objects.rows.0.name', 'COLOUR_V')
                ->where('objects.rows.0.type', 'VIEW'));

        $this->actingAs($this->ppc)->get('/oracle/source?q=COLOUR')
            ->assertInertia(fn ($page) => $page->where('objects.total', 2));
    } finally {
        $source->statement('drop view COLOUR_V');
    }
});

it('paginates without pulling the whole dictionary', function () {
    $total = count(standInTables());

    $first = $this->actingAs($this->ppc)->get('/oracle/source?page=1&per_page=5');
    $first->assertInertia(fn ($page) => $page->where('objects.page', 1)->where('objects.total', $total));

    // per_page is not a request parameter: the page size is the screen's, so a caller cannot ask for all
    // 5 419 objects at once.
    $first->assertInertia(fn ($page) => $page->where('objects.per_page', OracleSchemaBrowser::PER_PAGE));

    $this->actingAs($this->ppc)->get('/oracle/source?page=99')
        ->assertInertia(fn ($page) => $page->where('objects.page', 99)->where('objects.rows', []));
});

it('describes one object', function () {
    $owner = app(OracleSchemaBrowser::class)->defaultOwner();

    $this->actingAs($this->ppc)->get("/oracle/source/{$owner}/COLOURS")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('oracle/SourceBrowser')
            ->where('selected.name', 'COLOURS')
            ->where('selected.type', 'TABLE')
            ->where('selected.columns', fn ($columns) => collect($columns)->pluck('name')->contains('COLOUR_NAME'))
            ->where('selected.columns', fn ($columns) => collect($columns)->pluck('position')->all() === range(1, count($columns)))
            // The list is still rendered beside the detail: master/detail, not a separate screen.
            ->where('objects.total', count(standInTables())));
});

it('rejects an unknown or malformed object name cleanly', function () {
    $owner = app(OracleSchemaBrowser::class)->defaultOwner();

    $this->actingAs($this->ppc)->get("/oracle/source/{$owner}/NO_SUCH_TABLE")->assertNotFound();
    $this->actingAs($this->ppc)->get('/oracle/source/NO_SUCH_SCHEMA/COLOURS')->assertNotFound();

    // Anything that is not an identifier never reaches the service: the route pattern refuses it.
    foreach (['COLOURS%27', 'COLOUR%20S', 'COLOURS%3B--', 'COLOURS%22'] as $bad) {
        $this->actingAs($this->ppc)->get("/oracle/source/{$owner}/{$bad}")->assertNotFound();
    }

    // And the service itself says no, independently of the route.
    $browser = app(OracleSchemaBrowser::class);
    expect($browser->describe($owner, "COLOURS' or 1=1 --"))->toBeNull()
        ->and($browser->describe($owner, ''))->toBeNull()
        ->and($browser->describe('', 'COLOURS'))->toBeNull();
});

it('refuses a filter value that is not an identifier', function () {
    $this->actingAs($this->ppc)->get('/oracle/source?owner='.urlencode("x' or 1=1"))
        ->assertSessionHasErrors('owner');

    $this->actingAs($this->ppc)->get('/oracle/source?type=SYNONYM')->assertSessionHasErrors('type');
});

it('gates the screen on oracle.source.browse', function () {
    $this->get('/oracle/source')->assertRedirect('/login');

    $this->actingAs($this->viewer)->get('/oracle/source')->assertForbidden();

    $owner = app(OracleSchemaBrowser::class)->defaultOwner();
    $this->actingAs($this->viewer)->get("/oracle/source/{$owner}/COLOURS")->assertForbidden();

    expect($this->viewer->can('oracle.source.browse'))->toBeFalse()
        ->and($this->ppc->can('oracle.source.browse'))->toBeTrue();

    $this->actingAs($this->ppc)->get('/oracle/source')->assertOk();
});

/**
 * A browser whose listing fails the way a real one does. The failure is injected at the service boundary
 * rather than by breaking the connection: DB::purge() inside RefreshDatabase's wrapping transaction leaves
 * the suite unable to begin the next test's transaction.
 *
 * @param  list<string>  $missing
 */
function failingBrowser(?Throwable $failure = null, array $missing = []): void
{
    test()->mock(OracleSchemaBrowser::class, function (MockInterface $mock) use ($failure, $missing) {
        $mock->shouldReceive('connectionSummary')->andReturn([
            'driver' => 'oracle', 'mocked' => false, 'username' => 'NASIRPKG', 'host' => 'db.example',
            'port' => '1521', 'service' => 'NASIRPKG', 'schema_prefix' => '', 'server_version' => '11g',
        ]);
        $mock->shouldReceive('missingRequirements')->andReturn($missing);
        $mock->shouldReceive('defaultOwner')->andReturn('NASIRPKG');
        if ($failure !== null) {
            $mock->shouldReceive('owners')->andThrow($failure);
            $mock->shouldReceive('objects')->andThrow($failure);
            $mock->shouldReceive('describe')->andThrow($failure);
        }
    });
}

it('renders a message instead of throwing when the listener cannot be reached', function () {
    failingBrowser(new RuntimeException('ORA-12541: TNS:no listener (Connection: oracle_src)'));

    $this->actingAs($this->ppc)->get('/oracle/source')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('oracle/SourceBrowser')
            ->where('error.message', __('oracle.source.errors.unreachable'))
            ->where('error.code', 'ORA-12541')
            ->where('error.detail', 'ORA-12541: TNS:no listener')
            ->where('error.hints', [__('oracle.source.hints.listener_unreachable')])
            ->where('objects.total', 0)
            ->where('objects.rows', [])
            ->where('selected', null));
});

it('says so when the login may not read the dictionary', function () {
    failingBrowser(new RuntimeException('ORA-00942: table or view does not exist (Connection: oracle_src)'));

    $this->actingAs($this->ppc)->get('/oracle/source')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('error.message', __('oracle.source.errors.no_dictionary'))
            ->where('error.code', 'ORA-00942')
            ->where('error.hints', [__('oracle.source.hints.no_privilege')]));
});

it('says so when Oracle refuses the credentials', function () {
    failingBrowser(new RuntimeException('ORA-01017: invalid username/password; logon denied'));

    $this->actingAs($this->ppc)->get('/oracle/source')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('error.message', __('oracle.source.errors.credentials')));
});

it('says what is missing when the Oracle driver cannot be loaded at all', function () {
    failingBrowser(null, [OracleSourceDiagnostics::REQUIREMENT_OCI8, OracleSourceDiagnostics::REQUIREMENT_YAJRA]);

    $this->actingAs($this->ppc)->get('/oracle/source')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('error.message', __('oracle.source.errors.driver_unavailable'))
            ->where('error.code', null)
            ->where('error.hints', [
                __('oracle.source.requirements.oci8'),
                __('oracle.source.requirements.yajra'),
            ])
            ->where('objects.rows', []));
});

it('diagnoses the ORA- codes the screen and oracle:ping both report', function () {
    expect(OracleSourceDiagnostics::missingRequirements('mysql'))->toBe([])
        ->and(OracleSourceDiagnostics::missingRequirements('sqlite'))->toBe([])
        ->and(OracleSourceDiagnostics::driverUnavailable('mysql'))->toBeNull();

    $cases = [
        'ORA-12514: TNS:listener does not currently know of service' => OracleSourceDiagnostics::HINT_SERVICE_UNKNOWN,
        'ORA-12505: TNS:listener does not currently know of SID' => OracleSourceDiagnostics::HINT_SID_UNKNOWN,
        'ORA-01017: invalid username/password' => OracleSourceDiagnostics::HINT_BAD_CREDENTIALS,
        'ORA-12170: TNS:Connect timeout occurred' => OracleSourceDiagnostics::HINT_LISTENER_UNREACHABLE,
        'ORA-00942: table or view does not exist' => OracleSourceDiagnostics::HINT_NO_PRIVILEGE,
        'Unsupported driver [oracle]' => OracleSourceDiagnostics::HINT_UNSUPPORTED_DRIVER,
    ];

    foreach ($cases as $message => $code) {
        $e = new RuntimeException($message);
        expect(OracleSourceDiagnostics::hintCodes($e))->toContain($code)
            ->and(OracleSourceDiagnostics::connectionHints($e))->not->toBeEmpty();
    }

    // The ORA- line is preferred over oci8's "Error Code : 942" first line, and Laravel's connection
    // suffix is dropped.
    $e = new RuntimeException("Error Code : 942\nORA-00942: table or view does not exist (Connection: oracle_src, SQL: select 1)");
    expect(OracleSourceDiagnostics::firstLine($e))->toBe('ORA-00942: table or view does not exist')
        ->and(OracleSourceDiagnostics::oracleCode($e))->toBe('ORA-00942')
        ->and(OracleSourceDiagnostics::oracleCode(new RuntimeException('nothing oracular')))->toBe('');
});

it('reads and never writes', function () {
    $this->seed(OracleMockSeeder::class);
    $owner = app(OracleSchemaBrowser::class)->defaultOwner();

    $before = [];
    foreach (standInTables() as $table) {
        $before[$table] = DB::connection(OracleSourceConnection::NAME)->table($table)->count();
    }
    expect(array_sum($before))->toBeGreaterThan(0);

    /** @var list<string> $statements */
    $statements = [];
    // Connection::listen() registers on the application's event dispatcher, not on one connection, so
    // the connection name has to be checked here or the app's own inserts land in this list.
    DB::connection(OracleSourceConnection::NAME)->listen(function (QueryExecuted $query) use (&$statements) {
        if ($query->connectionName === OracleSourceConnection::NAME) {
            $statements[] = $query->sql;
        }
    });

    $this->actingAs($this->ppc)->get('/oracle/source')->assertOk();
    $this->actingAs($this->ppc)->get('/oracle/source?owner=&q=M&type=TABLE')->assertOk();
    $this->actingAs($this->ppc)->get("/oracle/source/{$owner}/PROCESSING_LOTS")->assertOk();

    expect($statements)->not->toBeEmpty();
    foreach ($statements as $sql) {
        expect(preg_match('/^\s*(insert|update|delete|drop|alter|create|truncate|merge|replace|grant|set)\b/i', $sql))
            ->toBe(0, "statement is not a read: {$sql}");
    }

    foreach ($before as $table => $count) {
        expect(DB::connection(OracleSourceConnection::NAME)->table($table)->count())
            ->toBe($count, "{$table} is unchanged");
    }

    // And the boundary that makes that structural rather than incidental.
    expect(fn () => OracleColour::query()->firstOrFail()->update(['COLOUR_NAME' => 'x']))
        ->toThrow(LogicException::class);
});
