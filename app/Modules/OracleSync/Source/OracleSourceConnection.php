<?php

namespace App\Modules\OracleSync\Source;

use RuntimeException;

/**
 * Which kind of Oracle source is configured. No Oracle instance exists yet, so `oracle_src` defaults to a
 * local MySQL stand-in; the day it points at the real thing, everything that writes to it — the mock schema
 * command and the mock seeder — must refuse, and that is what assertMocked() is for.
 */
final class OracleSourceConnection
{
    public const NAME = SourceModel::CONNECTION;

    /** Drivers that can only be a stand-in: the real Oracle is never one of these. */
    public const MOCK_DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    public static function driver(): string
    {
        return (string) config('database.connections.'.self::NAME.'.driver', '');
    }

    public static function isMocked(): bool
    {
        return in_array(self::driver(), self::MOCK_DRIVERS, true);
    }

    /**
     * Whether the connection is a real Oracle instance.
     *
     * Deliberately not `! isMocked()`: an unconfigured or misspelled driver is neither a stand-in nor
     * Oracle, and the two questions have different answers for it. Schema qualification asks *this* one —
     * `ERP.PARTIES` is only ever a schema-qualified Oracle name, while on MySQL it would silently mean
     * "database ERP, table PARTIES" and on SQLite nothing at all (SourceModel::getTable()).
     */
    public static function isOracle(): bool
    {
        return self::driver() === 'oracle';
    }

    /** @throws RuntimeException when the connection is (or might be) a real Oracle instance */
    public static function assertMocked(): void
    {
        if (! self::isMocked()) {
            throw new RuntimeException(
                'Refusing to write to the Oracle source: connection ['.self::NAME.'] uses the ['.self::driver().
                '] driver, which is not a local stand-in. Oracle is read-only to this application.'
            );
        }
    }
}
