<?php

namespace App\Modules\OracleSync\Source;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Throwable;

/**
 * Read-only discovery of what the `oracle_src` connection actually contains.
 *
 * This exists because the table names the application expected were not the instance's: `PARTY_M`,
 * `QUALITY_M`, `MACHINE_M` and the rest all answered ORA-00942, and nothing resolved under a single schema
 * prefix. Before anything could be mapped, somebody had to be able to see the real object names from inside
 * the application. That is all this does — it lists objects and describes their columns.
 *
 * It did its job on 2026-10-31: the masters turned out to live in `ERP` and the transactions in
 * `PRODUCTION`, `MACHINE_M` not to exist at all, and contracts to be master/detail over three tables
 * (docs/planning/findings.md §9). It stays, because the next unmapped object will need it too.
 *
 * Three rules shape every query below:
 *
 * 1. **SELECT only.** This is the client's live ERP. There is no write path here, no DDL, not even an
 *    `ALTER SESSION`. The data dictionary is read and nothing else.
 * 2. **Row counts come from optimizer statistics (ALL_TABLES.NUM_ROWS), never from `count(*)`.** Counting
 *    160 production tables to populate a listing is not acceptable; NUM_ROWS is free and already there.
 *    It can be null or stale, so LAST_ANALYZED travels with it and the screen says where the number
 *    came from rather than presenting it as the truth.
 * 3. **No identifier is ever interpolated.** Owner, name and the search term are bound parameters, and no
 *    statement anywhere names a discovered table — the dictionary views are the only tables queried, which
 *    is also why this class cannot be talked into selecting business rows out of the ERP.
 *
 * It is driver-aware because the test suite and every dev machine run `oracle_src` as a SQLite/MySQL
 * stand-in (phpunit.xml pins it to in-memory SQLite). On Oracle it reads ALL_OBJECTS / ALL_TABLES /
 * ALL_TAB_COLUMNS; on a stand-in it goes through Laravel's driver-agnostic schema builder.
 *
 * On the Oracle path the dictionary views are used rather than yajra's own `getTables()` / `getColumns()`,
 * and that is a measured decision, not an oversight — see the notes on oracleObjects() and oracleColumns().
 *
 * @phpstan-type ObjectRow array{owner: string, name: string, type: string, columns: int|null, rows: int|null, analysed_at: string|null}
 * @phpstan-type ColumnRow array{position: int, name: string, type: string, nullable: bool, default: string|null}
 * @phpstan-type ObjectPage array{rows: list<ObjectRow>, total: int, page: int, per_page: int, pages: int}
 */
class OracleSchemaBrowser
{
    public const TYPE_TABLE = 'TABLE';

    public const TYPE_VIEW = 'VIEW';

    /** The only object types this screen deals in: structure that has columns to describe. */
    public const TYPES = [self::TYPE_TABLE, self::TYPE_VIEW];

    public const PER_PAGE = 50;

    /** Oracle identifiers are 30 characters on 10g/11g and 128 from 12.2; the longer bound is the safe one. */
    public const MAX_IDENTIFIER = 128;

    /** @var list<array{owner: string, name: string, type: string}>|null the stand-in's objects, read once per request */
    private ?array $standIn = null;

    public function __construct(private readonly DatabaseManager $db) {}

    public function isOracle(): bool
    {
        return OracleSourceConnection::driver() === 'oracle';
    }

    /**
     * What this machine is missing before the connection can be opened at all (empty when it can).
     *
     * @return list<string>
     */
    public function missingRequirements(): array
    {
        return OracleSourceDiagnostics::missingRequirements(OracleSourceConnection::driver());
    }

    /**
     * The connection as configured, for the screen's header. The password is not in here and never will be.
     *
     * @return array{driver: string, mocked: bool, username: string, host: string, port: string, service: string, schema_prefix: string, server_version: string}
     */
    public function connectionSummary(): array
    {
        return [
            'driver' => OracleSourceConnection::driver(),
            'mocked' => OracleSourceConnection::isMocked(),
            'username' => $this->setting('username'),
            'host' => $this->setting('host'),
            'port' => $this->setting('port'),
            // Whichever of the three addressing forms is set; yajra prefers them in this order.
            'service' => $this->setting('tns') !== '' ? $this->setting('tns')
                : ($this->setting('service_name') !== '' ? $this->setting('service_name') : $this->setting('database')),
            'schema_prefix' => $this->setting('prefix_schema'),
            'server_version' => $this->setting('server_version'),
        ];
    }

    /**
     * The owner the screen opens on: the login's own schema (or the configured schema prefix).
     *
     * 703 SYS tables are not what anyone came here to look at — the 195 objects this login owns are. The
     * other owners stay one dropdown away, they are simply not the default.
     */
    public function defaultOwner(): string
    {
        if ($this->isOracle()) {
            $prefix = $this->setting('prefix_schema');

            return strtoupper($prefix !== '' ? $prefix : $this->setting('username'));
        }

        $owners = array_column($this->standInObjects(), 'owner');

        return $owners === [] ? $this->setting('database') : (string) $owners[0];
    }

    /**
     * Every owner with at least one table or view, and how many each has.
     *
     * @return list<array{owner: string, objects: int}>
     */
    public function owners(): array
    {
        if (! $this->isOracle()) {
            $counts = array_count_values(array_column($this->standInObjects(), 'owner'));
            ksort($counts);

            return array_map(
                fn (string $owner, int $n): array => ['owner' => $owner, 'objects' => $n],
                array_keys($counts),
                array_values($counts),
            );
        }

        $rows = $this->connection()->select(
            'select o.owner as "owner", count(*) as "objects" from all_objects o'
            ." where o.object_type in ('TABLE', 'VIEW') group by o.owner order by o.owner"
        );

        return array_values(array_map(fn (mixed $row): array => [
            'owner' => $this->str($row, 'owner'),
            'objects' => (int) $this->raw($row, 'objects'),
        ], $rows));
    }

    /**
     * One page of the object list, filtered and paginated by the database — never in PHP: on the mill's
     * instance this dictionary holds 5 419 tables and views across 16 owners, which is far too many to
     * fetch and sift through in memory.
     *
     * @return ObjectPage
     */
    public function objects(?string $search = null, ?string $owner = null, ?string $type = null, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $type = in_array($type, self::TYPES, true) ? $type : null;

        [$rows, $total] = $this->isOracle()
            ? $this->oracleObjects($search, $owner, $type, $page, $perPage)
            : $this->standInPage($search, $owner, $type, $page, $perPage);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * One object's columns, or null when the dictionary does not have it under that owner and name.
     *
     * The null is what makes the describe action safe: owner and name are resolved against the dictionary
     * before anything else happens, so an unknown or malformed one is a 404 rather than a query. The
     * resolved spelling — Oracle's own, not the request's — is what the column queries then bind.
     *
     * @return array{owner: string, name: string, type: string, columns: list<ColumnRow>}|null
     */
    public function describe(string $owner, string $name): ?array
    {
        $owner = trim($owner);
        $name = trim($name);

        if (! $this->isIdentifier($owner) || ! $this->isIdentifier($name)) {
            return null;
        }

        $object = $this->resolveObject($owner, $name);

        if ($object === null) {
            return null;
        }

        return $object + [
            'columns' => $this->isOracle()
                ? $this->oracleColumns($object['owner'], $object['name'])
                : $this->standInColumns($object['name']),
        ];
    }

    /* ---------------------------------------------------------------- Oracle */

    /**
     * ALL_OBJECTS joined to ALL_TABLES, paginated with a ROWNUM subquery.
     *
     * yajra does implement `getTables()` / `getColumns()`, so the driver-agnostic route was tried first.
     * It cannot do this job: its compileTables() lists one schema at a time, covers `table_type in
     * ('TABLE')` only — the 35 views in this schema would vanish — reports no object type, lowercases the
     * names Oracle stores in upper case, and interpolates the schema straight into the SQL text. It also
     * has no filter, no ordering and no pagination, which is the whole point here.
     *
     * The ROWNUM form is not a stylistic choice either: the instance is Oracle 10.2.0.3 and
     * `OFFSET … FETCH NEXT` does not exist before 12.1 (hence server_version=11g in config/database.php).
     * This method writes that subquery itself rather than trusting a paginator to compile it.
     *
     * @return array{0: list<ObjectRow>, 1: int}
     */
    private function oracleObjects(?string $search, ?string $owner, ?string $type, int $page, int $perPage): array
    {
        [$where, $bindings] = $this->oracleWhere($search, $owner, $type);
        $connection = $this->connection();

        $total = (int) $this->raw(
            $connection->selectOne('select count(*) as "total" from all_objects o where '.$where, $bindings),
            'total'
        );

        if ($total === 0) {
            return [[], 0];
        }

        $inner = 'select o.owner as "owner", o.object_name as "object_name", o.object_type as "object_type",'
            .' t.num_rows as "num_rows", t.last_analyzed as "last_analyzed"'
            .' from all_objects o'
            .' left join all_tables t on t.owner = o.owner and t.table_name = o.object_name'
            .' where '.$where
            .' order by o.owner, o.object_name';

        $offset = ($page - 1) * $perPage;
        $rows = $connection->select(
            'select * from (select r.*, rownum as "rn" from ('.$inner.') r where rownum <= ?) where "rn" > ?',
            [...$bindings, $offset + $perPage, $offset],
        );

        $objects = array_values(array_map(fn (mixed $row): array => [
            'owner' => $this->str($row, 'owner'),
            'name' => $this->str($row, 'object_name'),
            'type' => $this->str($row, 'object_type'),
            'columns' => null,
            'rows' => $this->raw($row, 'num_rows') === null ? null : (int) $this->raw($row, 'num_rows'),
            'analysed_at' => $this->date($this->str($row, 'last_analyzed')),
        ], $rows));

        return [$this->withOracleColumnCounts($objects), $total];
    }

    /**
     * The WHERE clause for the object list: fixed SQL text, every value bound.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function oracleWhere(?string $search, ?string $owner, ?string $type): array
    {
        /** @var list<string> $bindings */
        $bindings = [];

        if ($type !== null) {
            $clauses = ['o.object_type = ?'];
            $bindings[] = $type;
        } else {
            $clauses = ["o.object_type in ('TABLE', 'VIEW')"];
        }

        $owner = $owner === null ? '' : strtoupper(trim($owner));
        if ($owner !== '' && $this->isIdentifier($owner)) {
            $clauses[] = 'o.owner = ?';
            $bindings[] = $owner;
        }

        $term = $search === null ? '' : trim($search);
        if ($term !== '') {
            // Case-insensitive contains. The term's own % and _ are escaped so a search for "LOT_NUM"
            // cannot turn into a wildcard, and the pattern is bound, not pasted in.
            $clauses[] = 'upper(o.object_name) like ? escape \'\\\'';
            $bindings[] = '%'.addcslashes(strtoupper($term), '%_\\').'%';
        }

        return [implode(' and ', $clauses), $bindings];
    }

    /**
     * Column counts for the rows on this page only — one extra dictionary query for at most `per_page`
     * objects, instead of aggregating ALL_TAB_COLUMNS over every table in the instance.
     *
     * @param  list<ObjectRow>  $objects
     * @return list<ObjectRow>
     */
    private function withOracleColumnCounts(array $objects): array
    {
        if ($objects === []) {
            return [];
        }

        /** @var list<string> $bindings */
        $bindings = [];
        foreach ($objects as $object) {
            $bindings[] = $object['owner'];
            $bindings[] = $object['name'];
        }
        $pairs = implode(', ', array_fill(0, count($objects), '(?, ?)'));

        try {
            $rows = $this->connection()->select(
                'select owner as "owner", table_name as "table_name", count(*) as "cnt" from all_tab_columns'
                .' where (owner, table_name) in ('.$pairs.') group by owner, table_name',
                $bindings,
            );
        } catch (Throwable) {
            // A listing without column counts is still a useful listing; it must not cost the whole page.
            return $objects;
        }

        $counts = [];
        foreach ($rows as $row) {
            $counts[$this->str($row, 'owner').'.'.$this->str($row, 'table_name')] = (int) $this->raw($row, 'cnt');
        }

        return array_map(function (array $object) use ($counts): array {
            $object['columns'] = $counts[$object['owner'].'.'.$object['name']] ?? null;

            return $object;
        }, $objects);
    }

    /**
     * ALL_TAB_COLUMNS for one object, as two queries on purpose.
     *
     * DATA_DEFAULT is a LONG, and oci8 fetches LONGs one row at a time with no prefetch: including it in
     * the main select took a measured 6.3 s for a 44-column table against the mill's instance, versus
     * 145 ms without it. Asking for defaults only where there is one (`data_default is not null`, which
     * Oracle does allow on a LONG) brings that back to ~300 ms, because almost no column has a default.
     *
     * yajra's own getColumns() is not used here: it lowercases the names, renders Laravel's type names
     * rather than Oracle's (`int`, `decimal`) and pastes owner and table straight into the SQL text — and
     * this screen's whole output is Oracle's own rendering of its types, from bound parameters.
     *
     * @return list<ColumnRow>
     */
    private function oracleColumns(string $owner, string $name): array
    {
        $rows = $this->connection()->select(
            'select column_name as "column_name", data_type as "data_type", data_length as "data_length",'
            .' data_precision as "data_precision", data_scale as "data_scale", char_length as "char_length",'
            .' nullable as "nullable", column_id as "column_id"'
            .' from all_tab_columns where owner = ? and table_name = ? order by column_id',
            [$owner, $name],
        );

        $defaults = $this->oracleColumnDefaults($owner, $name);

        $columns = [];
        foreach ($rows as $i => $row) {
            $column = $this->str($row, 'column_name');
            $position = $this->raw($row, 'column_id');
            $columns[] = [
                'position' => $position === null ? $i + 1 : (int) $position,
                'name' => $column,
                'type' => $this->oracleType($row),
                'nullable' => strtoupper($this->str($row, 'nullable')) === 'Y',
                'default' => $defaults[$column] ?? null,
            ];
        }

        return $columns;
    }

    /** @return array<string, string> column name => default expression */
    private function oracleColumnDefaults(string $owner, string $name): array
    {
        try {
            $rows = $this->connection()->select(
                'select column_name as "column_name", data_default as "data_default" from all_tab_columns'
                .' where owner = ? and table_name = ? and data_default is not null order by column_id',
                [$owner, $name],
            );
        } catch (Throwable) {
            // The structure is the point; a dictionary that will not hand over LONGs must not hide it.
            return [];
        }

        $defaults = [];
        foreach ($rows as $row) {
            $value = trim($this->str($row, 'data_default'));
            if ($value !== '') {
                $defaults[$this->str($row, 'column_name')] = $value;
            }
        }

        return $defaults;
    }

    /** The type as Oracle itself writes it: VARCHAR2(40), NUMBER(12,2), NUMBER, DATE. */
    private function oracleType(mixed $row): string
    {
        $type = strtoupper($this->str($row, 'data_type'));
        $precision = $this->raw($row, 'data_precision');
        $scale = $this->raw($row, 'data_scale');

        if (in_array($type, ['NUMBER', 'FLOAT'], true)) {
            if ($precision === null || $precision === '') {
                return $type;
            }

            return $type.'('.(int) $precision.((int) $scale !== 0 ? ','.(int) $scale : '').')';
        }

        if (in_array($type, ['VARCHAR2', 'NVARCHAR2', 'VARCHAR', 'CHAR', 'NCHAR', 'RAW'], true)) {
            $length = (int) ($this->raw($row, 'char_length') ?? 0);
            if ($length === 0) {
                $length = (int) ($this->raw($row, 'data_length') ?? 0);
            }

            return $length > 0 ? $type.'('.$length.')' : $type;
        }

        // DATE, TIMESTAMP(6), CLOB, BLOB, LONG and the rest already read the way Oracle writes them.
        return $type;
    }

    /**
     * The object as the dictionary has it: its stored owner, name and type, or null when this login cannot
     * see it.
     *
     * Two queries in the worst case, on purpose. Oracle folds unquoted identifiers to upper case, so the
     * bound equality below matches almost everything and can use the dictionary's indexes; only when it
     * finds nothing is the case-insensitive form tried, which is what reaches a quoted, mixed-case name
     * such as SYS's `_default_auditing_options_`. Both forms bind their values.
     *
     * @return array{owner: string, name: string, type: string}|null
     */
    private function resolveObject(string $owner, string $name): ?array
    {
        if (! $this->isOracle()) {
            foreach ($this->standInObjects() as $object) {
                if (strcasecmp($object['owner'], $owner) === 0 && strcasecmp($object['name'], $name) === 0) {
                    return $object;
                }
            }

            return null;
        }

        $select = 'select owner as "owner", object_name as "object_name", object_type as "object_type" from all_objects';
        $types = " and object_type in ('TABLE', 'VIEW') and rownum = 1";

        $row = $this->connection()->selectOne(
            $select.' where owner = ? and object_name = ?'.$types,
            [strtoupper($owner), strtoupper($name)],
        ) ?? $this->connection()->selectOne(
            $select.' where upper(owner) = ? and upper(object_name) = ?'.$types,
            [strtoupper($owner), strtoupper($name)],
        );

        return $row === null ? null : [
            'owner' => $this->str($row, 'owner'),
            'name' => $this->str($row, 'object_name'),
            'type' => $this->str($row, 'object_type'),
        ];
    }

    /* -------------------------------------------------------------- stand-in */

    /**
     * The stand-in's tables and views through Laravel's driver-agnostic schema builder.
     *
     * The brief's instruction was to try this first, and on SQLite/MySQL it is the right tool: there is no
     * portable dictionary view to filter in SQL across those drivers, and getTables() takes no filter. It
     * is read once per request and sifted in PHP, which is sound here and only here — the stand-in holds
     * the eleven Oracle-shaped mock tables, not 5 419 objects.
     *
     * @return list<array{owner: string, name: string, type: string}>
     */
    private function standInObjects(): array
    {
        if ($this->standIn !== null) {
            return $this->standIn;
        }

        $schema = $this->schema();
        $fallback = $this->setting('database');
        $objects = [];

        foreach ([self::TYPE_TABLE => $schema->getTables(), self::TYPE_VIEW => $schema->getViews()] as $type => $found) {
            foreach ($found as $item) {
                if ($item['name'] === '') {
                    continue;
                }
                // SQLite reports 'main', MySQL the database name; neither is an Oracle owner, but both are
                // the right thing to show in the Owner column, and null falls back to the configured one.
                $owner = $item['schema'];
                $objects[] = [
                    'owner' => $owner === null || $owner === '' ? $fallback : $owner,
                    'name' => $item['name'],
                    'type' => (string) $type,
                ];
            }
        }

        usort($objects, fn (array $a, array $b): int => [$a['owner'], $a['name']] <=> [$b['owner'], $b['name']]);

        return $this->standIn = $objects;
    }

    /**
     * @return array{0: list<ObjectRow>, 1: int}
     */
    private function standInPage(?string $search, ?string $owner, ?string $type, int $page, int $perPage): array
    {
        $term = $search === null ? '' : trim($search);
        $owner = $owner === null ? '' : trim($owner);

        $matched = array_values(array_filter($this->standInObjects(), function (array $object) use ($term, $owner, $type): bool {
            if ($type !== null && $object['type'] !== $type) {
                return false;
            }
            if ($owner !== '' && strcasecmp($object['owner'], $owner) !== 0) {
                return false;
            }

            return $term === '' || mb_stripos($object['name'], $term) !== false;
        }));

        $rows = [];
        foreach (array_slice($matched, ($page - 1) * $perPage, $perPage) as $object) {
            $rows[] = [
                'owner' => $object['owner'],
                'name' => $object['name'],
                'type' => $object['type'],
                'columns' => $this->standInColumnCount($object['name']),
                // No optimizer statistics on a stand-in, and a count(*) is exactly what this screen must
                // not do. Null is the honest answer and the screen renders it as "—".
                'rows' => null,
                'analysed_at' => null,
            ];
        }

        return [$rows, count($matched)];
    }

    private function standInColumnCount(string $name): ?int
    {
        try {
            return count($this->schema()->getColumns($name));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<ColumnRow> */
    private function standInColumns(string $name): array
    {
        // The name has already been matched against standInObjects(), so this is the stand-in's own
        // spelling of it rather than anything that arrived from the request.
        foreach ($this->standInObjects() as $object) {
            if (strtoupper($object['name']) === strtoupper($name)) {
                $name = $object['name'];
                break;
            }
        }

        $columns = [];
        foreach ($this->schema()->getColumns($name) as $i => $column) {
            $default = $column['default'];
            $columns[] = [
                'position' => $i + 1,
                'name' => $column['name'],
                'type' => strtoupper($column['type']),
                'nullable' => $column['nullable'],
                'default' => is_scalar($default) ? (string) $default : null,
            ];
        }

        return $columns;
    }

    /* --------------------------------------------------------------- plumbing */

    private function connection(): ConnectionInterface
    {
        return $this->db->connection(OracleSourceConnection::NAME);
    }

    private function schema(): SchemaBuilder
    {
        /** @var Connection $connection */
        $connection = $this->db->connection(OracleSourceConnection::NAME);

        return $connection->getSchemaBuilder();
    }

    /**
     * Characters an Oracle identifier can hold, so a malformed owner or name is refused before it reaches
     * a bind at all. Deliberately wider than unquoted Oracle allows (leading digits and underscores do
     * occur among SYS's quoted names), and still only ever letters, digits and `_$#`.
     */
    private function isIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_$#]{1,'.self::MAX_IDENTIFIER.'}$/', $value) === 1;
    }

    private function setting(string $key): string
    {
        $value = config('database.connections.'.OracleSourceConnection::NAME.'.'.$key);

        return is_scalar($value) ? (string) $value : '';
    }

    /** oci8 returns columns in whichever case the alias was written, so both are tried. */
    private function raw(mixed $row, string $column): mixed
    {
        if (! is_object($row)) {
            return null;
        }

        foreach ([$column, strtoupper($column)] as $name) {
            if (property_exists($row, $name)) {
                return $row->{$name};
            }
        }

        return null;
    }

    private function str(mixed $row, string $column): string
    {
        $value = $this->raw($row, $column);

        return is_scalar($value) ? (string) $value : '';
    }

    /** The date part of an Oracle DATE as oci8 hands it over ("2026-10-22 22:00:08"). */
    private function date(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : substr($value, 0, 10);
    }
}
