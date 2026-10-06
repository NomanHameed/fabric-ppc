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
 */
abstract class SourceModel extends Model
{
    public const CONNECTION = 'oracle_src';

    protected $connection = self::CONNECTION;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

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
