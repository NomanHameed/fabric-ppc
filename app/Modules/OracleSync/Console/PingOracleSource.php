<?php

namespace App\Modules\OracleSync\Console;

use App\Modules\OracleSync\Source\OracleSourceConnection;
use App\Modules\OracleSync\Source\OracleSourceDiagnostics;
use App\Modules\OracleSync\Source\SourceModel;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only diagnostic for the `oracle_src` connection: does it connect at all, who does Oracle think we are,
 * and which of the source tables can this login actually select from.
 *
 * Every statement here is a SELECT. Nothing on this connection may ever be written (SourceModel refuses
 * save/delete outright), so this command exists to answer "is the grant set complete?" without an INSERT, a
 * DDL or a `SELECT … FOR UPDATE` anywhere near the mill's ERP.
 *
 * It is deliberately tolerant per item: a table that is missing or not granted is reported with its ORA- code
 * and the other tables are still checked, because the useful answer is the whole list, not the first failure.
 */
class PingOracleSource extends Command
{
    protected $signature = 'oracle:ping {--tables-only : Skip the banner and session queries (for an account without v$version)}';

    protected $description = 'Check the Oracle source connection and report which source tables are readable (read-only)';

    public function handle(): int
    {
        $driver = OracleSourceConnection::driver();

        $this->printSettings($driver);

        if (OracleSourceConnection::isMocked()) {
            $this->warn('This is the local stand-in (driver ['.$driver.']), not a real Oracle instance.');
        } else {
            $this->info('Configured as a real Oracle instance, not the local stand-in. The application only reads from it; writes are refused.');
        }
        $this->newLine();

        if (($unavailable = OracleSourceDiagnostics::driverUnavailable($driver)) !== null) {
            $this->error($unavailable);

            return self::FAILURE;
        }

        $connection = $this->probe($driver);
        if ($connection === null) {
            return self::FAILURE;
        }

        if ($driver === 'oracle' && ! $this->option('tables-only')) {
            $this->reportBanner($connection);
            $this->reportSession($connection);
        }

        return $this->reportTables($connection, $driver);
    }

    /** The effective settings, password excluded — never printed, not even masked. */
    private function printSettings(string $driver): void
    {
        $this->line('Oracle source connection ['.OracleSourceConnection::NAME.']');

        $rows = [
            ['Driver', $driver],
            ['Host', $this->setting('host')],
            ['Port', $this->setting('port')],
        ];

        // Only whichever of the three addressing forms is actually set; yajra prefers them in this order.
        foreach ([
            'TNS descriptor' => 'tns',
            'Service name' => 'service_name',
            ($driver === 'oracle' ? 'Database (SID)' : 'Database') => 'database',
        ] as $label => $key) {
            if ($this->setting($key) !== '') {
                $rows[] = [$label, $this->setting($key)];
            }
        }

        $rows[] = ['Username', $this->setting('username')];
        $rows[] = ['Password', $this->setting('password') === '' ? '(not set)' : '(set — not shown)'];
        $rows[] = ['Charset', $this->setting('charset')];
        $rows[] = ['Schema prefix', $this->setting('prefix_schema') === '' ? '(none)' : $this->setting('prefix_schema')];
        $rows[] = ['Server version', $this->setting('server_version')];

        $this->table(['Setting', 'Value'], $rows);

        if ($this->setting('tns') === '' && $this->setting('service_name') === '' && $this->setting('database') === '') {
            $this->warn('No ORACLE_SRC_TNS, ORACLE_SRC_SERVICE_NAME or ORACLE_SRC_DATABASE is set.');
        }
    }

    /** Opens the connection and runs one trivial, driver-correct SELECT. Null when it did not work. */
    private function probe(string $driver): ?ConnectionInterface
    {
        $sql = $driver === 'oracle' ? 'select 1 as ok from dual' : 'select 1 as ok';

        try {
            $connection = DB::connection(OracleSourceConnection::NAME);
            $startedAt = hrtime(true);
            $connection->select($sql);
            $ms = (hrtime(true) - $startedAt) / 1_000_000;
        } catch (Throwable $e) {
            $this->error('Could not connect to ['.OracleSourceConnection::NAME.']: '.OracleSourceDiagnostics::firstLine($e));
            foreach (OracleSourceDiagnostics::connectionHints($e) as $hint) {
                $this->line('  '.$hint);
            }

            return null;
        }

        $this->info(sprintf('Connected. Probe [%s] answered in %.1f ms.', $sql, $ms));

        return $connection;
    }

    /** Often ungranted on a least-privilege account, so a failure here is a warning and nothing more. */
    private function reportBanner(ConnectionInterface $connection): void
    {
        try {
            $row = $connection->selectOne('select banner from v$version where rownum = 1');
            $this->line('Banner: '.$this->value($row, 'banner'));
        } catch (Throwable $e) {
            $this->warn('Could not read v$version (no grant on it is normal): '.OracleSourceDiagnostics::firstLine($e));
        }
    }

    private function reportSession(ConnectionInterface $connection): void
    {
        $sql = "select user as db_user, sys_context('USERENV','DB_NAME') as db_name,"
            ." sys_context('USERENV','SERVICE_NAME') as service_name,"
            ." to_char(sysdate,'YYYY-MM-DD HH24:MI:SS') as db_time from dual";

        try {
            $row = $connection->selectOne($sql);
            $this->table(
                ['Oracle user', 'DB name', 'Service name', 'DB time'],
                [[
                    $this->value($row, 'db_user'),
                    $this->value($row, 'db_name'),
                    $this->value($row, 'service_name'),
                    $this->value($row, 'db_time'),
                ]],
            );
        } catch (Throwable $e) {
            $this->warn('Could not read the session context: '.OracleSourceDiagnostics::firstLine($e));
        }
    }

    /** One row per source table: visible or not, and how many rows. Exit 1 when any of them is unreadable. */
    private function reportTables(ConnectionInterface $connection, string $driver): int
    {
        $tables = $this->sourceTables();
        $rows = [];
        /** @var list<string> $unreadable */
        $unreadable = [];

        foreach ($tables as $table => $class) {
            try {
                $count = $class::query()->count();
                $rows[] = [$table, 'yes', number_format($count)];
            } catch (Throwable $e) {
                $unreadable[] = $table;
                $code = OracleSourceDiagnostics::oracleCode($e);
                $rows[] = [$table, 'NO'.($code === '' ? '' : ' ('.$code.')'), OracleSourceDiagnostics::firstLine($e)];
            }
        }

        $this->newLine();
        $this->table(['Table', 'Readable', 'Rows / error'], $rows);

        $total = count($tables);
        $readable = $total - count($unreadable);
        $line = $readable.' of '.$total.' source table(s) readable.';

        if ($unreadable === []) {
            $this->info($line);

            return self::SUCCESS;
        }

        $this->error($line.' Grant SELECT on the rest, or correct the owner the model declares'
            .' ($oracleSchema in App\\Modules\\OracleSync\\Source\\Models).');

        if ($driver === 'oracle') {
            $this->reportOwners($connection, $unreadable);
        }

        return self::FAILURE;
    }

    /**
     * Where the unreadable tables actually live, so an ORA-00942 is actionable rather than a dead end: a
     * table owned by another schema needs its model's `$oracleSchema` corrected, one that is nowhere in
     * ALL_OBJECTS needs a grant from the DBA. ALL_OBJECTS is a read of the data dictionary and nothing more.
     *
     * The owner is stripped off before binding, because the names reaching here are already qualified
     * (`ERP.PARTIES`) and ALL_OBJECTS.OBJECT_NAME holds the bare name.
     *
     * @param  list<string>  $tables
     */
    private function reportOwners(ConnectionInterface $connection, array $tables): void
    {
        $tables = array_values(array_unique(array_map(
            fn (string $table): string => str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table,
            $tables,
        )));

        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $sql = 'select owner, object_name, object_type from all_objects'
            ." where object_name in ({$placeholders}) and object_type in ('TABLE', 'VIEW', 'SYNONYM')"
            .' order by owner, object_name';

        try {
            $found = $connection->select($sql, $tables);
        } catch (Throwable $e) {
            $this->warn('Could not read ALL_OBJECTS to locate them: '.OracleSourceDiagnostics::firstLine($e));

            return;
        }

        if ($found === []) {
            $this->warn('None of them appear in ALL_OBJECTS for this login, so they are not merely unqualified:'
                .' this account has no privilege on them at all. Ask the DBA for SELECT (or a synonym).');

            return;
        }

        $this->line('They do exist and are visible in the dictionary under these owners:');
        // Then the fix is the model's own declaration, not a global prefix: the source spans two schemas.
        $this->table(
            ['Owner', 'Object', 'Type'],
            array_map(fn (mixed $row): array => [
                $this->value($row, 'owner'),
                $this->value($row, 'object_name'),
                $this->value($row, 'object_type'),
            ], $found),
        );
        $this->line('Point the model\'s $oracleSchema at the owning schema and run this again.');
    }

    /**
     * The source tables, taken from the models in Source\Models — that directory is the source of truth, so a
     * model added later is pinged without a second copy of the table names living here.
     *
     * @return array<string, class-string<SourceModel>> table name => model class
     */
    private function sourceTables(): array
    {
        $tables = [];

        foreach ((array) glob(app_path('Modules/OracleSync/Source/Models/*.php')) as $file) {
            $class = 'App\\Modules\\OracleSync\\Source\\Models\\'.basename((string) $file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, SourceModel::class)) {
                continue;
            }

            /** @var SourceModel $model */
            $model = new $class;
            $tables[$model->getTable()] = $class;
        }

        ksort($tables);

        return $tables;
    }

    private function setting(string $key): string
    {
        $value = config('database.connections.'.OracleSourceConnection::NAME.'.'.$key);

        return is_scalar($value) ? (string) $value : '';
    }

    private function value(mixed $row, string $column): string
    {
        if (! is_object($row)) {
            return '';
        }

        // oci8 can return either case depending on how the column was aliased.
        foreach ([$column, strtoupper($column)] as $name) {
            if (property_exists($row, $name) && is_scalar($row->{$name})) {
                return (string) $row->{$name};
            }
        }

        return '';
    }
}
