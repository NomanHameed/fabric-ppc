<?php

namespace App\Modules\OracleSync\Source;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Base class for everything that lives in Oracle.
 *
 * Oracle is a *source*. It holds the transactions; this application holds status, timeline and user actions,
 * and never writes back. That boundary is enforced here rather than left to discipline: every model on the
 * `oracle_src` connection refuses to save or delete, so a stray `->save()` fails in a test rather than
 * silently writing to the mill's ERP the day a real connection is configured.
 *
 * Oracle supplied no keys, no sequences and no timestamps, so these models declare none of Laravel's.
 *
 * ## Schema qualification
 *
 * The source data is spread over **two** Oracle schemas — the transactions in `PRODUCTION`, the code
 * masters in `ERP` (verified against the live instance on 2026-10-31, docs/planning/findings.md §9) — so no
 * single `ORACLE_SRC_SCHEMA_PREFIX` can address it. Each model therefore names its own owner in
 * `$oracleSchema`, and getTable() qualifies the table **only when the connection really is Oracle**:
 *
 *   - driver `oracle`   → `ERP.PARTIES`, which yajra wraps as `"ERP"."PARTIES"`.
 *   - any other driver  → `PARTIES`, because on MySQL `ERP.PARTIES` means "database ERP, table PARTIES"
 *     and on SQLite (what phpunit.xml pins the suite to) it is not addressable at all.
 *
 * The mock tables are therefore named exactly like the real ones minus the owner, and nothing in the
 * application has to know which of the two it is talking to.
 */
abstract class SourceModel extends Model
{
    public const CONNECTION = 'oracle_src';

    /** The transaction schema: lots, greige issues, processing contracts. */
    public const SCHEMA_PRODUCTION = 'PRODUCTION';

    /** The shared ERP schema: parties, qualities, designs, colours, cost centres, operations. */
    public const SCHEMA_ERP = 'ERP';

    protected $connection = self::CONNECTION;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    /** The Oracle schema (owner) that owns this object. Empty means "do not qualify". */
    protected string $oracleSchema = '';

    protected static function booted(): void
    {
        $refuse = static function (Model $model): void {
            throw new LogicException(
                'The Oracle source is read-only: '.static::class.' may not be written to from this application.'
            );
        };

        static::saving($refuse);
        static::deleting($refuse);
    }

    /** `OWNER.TABLE` on a real Oracle instance, the bare table name on a local stand-in. */
    public function getTable(): string
    {
        $table = $this->unqualifiedTable();

        if ($this->oracleSchema === '' || ! OracleSourceConnection::isOracle()) {
            return $table;
        }

        return $this->oracleSchema.'.'.$table;
    }

    /** The table name without its owner — what the mock schema and the mock seeder use. */
    public function unqualifiedTable(): string
    {
        return parent::getTable();
    }

    /** The Oracle schema this object belongs to, whatever the configured driver. */
    public function oracleSchema(): string
    {
        return $this->oracleSchema;
    }

    /**
     * The mock seeder's only way in. Named so it reads as what it is — filling the stand-in, never a write to
     * Oracle — and it refuses to run when the connection is not a local stand-in.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function seedMock(array $rows): void
    {
        OracleSourceConnection::assertMocked();

        foreach (array_chunk($rows, 250) as $chunk) {
            static::query()->getQuery()->insert($chunk);
        }
    }
}
