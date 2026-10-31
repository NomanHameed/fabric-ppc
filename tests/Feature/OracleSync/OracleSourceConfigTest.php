<?php

use App\Modules\OracleSync\Source\OracleSourceConnection;

/**
 * The `oracle_src` connection has to be two things at once: a real Oracle instance when
 * ORACLE_SRC_DRIVER=oracle, and the MySQL/SQLite stand-in for every other driver. These tests resolve
 * config/database.php the way the framework does — re-evaluating the file with the environment in place —
 * rather than reading it as text, so they fail if the conditional is moved or renamed.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function oracleSrcConnectionConfig(array $env): array
{
    // Restored, not unset: phpunit.xml pins ORACLE_SRC_DRIVER/DATABASE for the whole process, and clearing
    // them here would hand every later test in this process the developer's real .env.
    $previous = [];

    foreach ($env as $key => $value) {
        $previous[$key] = [
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
        ];
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key.'='.$value);
    }

    try {
        /** @var array{connections: array<string, array<string, mixed>>} $database */
        $database = require config_path('database.php');

        return $database['connections'][OracleSourceConnection::NAME];
    } finally {
        foreach ($previous as $key => $was) {
            if ($was['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $was['server'];
            }

            if ($was['env'] === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $was['env'];
                putenv($key.'='.(is_scalar($was['env']) ? (string) $was['env'] : ''));
            }
        }
    }
}

it('uses the Oracle client charset for the oracle driver and the MySQL one for the stand-in', function () {
    // utf8mb4 is a MySQL name; yajra hands `charset` straight to oci_connect(), which rejects it.
    expect(oracleSrcConnectionConfig(['ORACLE_SRC_DRIVER' => 'oracle'])['charset'])->toBe('AL32UTF8')
        ->and(oracleSrcConnectionConfig(['ORACLE_SRC_DRIVER' => 'mysql'])['charset'])->toBe('utf8mb4')
        ->and(oracleSrcConnectionConfig(['ORACLE_SRC_DRIVER' => 'mariadb'])['charset'])->toBe('utf8mb4');
});

it('lets ORACLE_SRC_CHARSET override the charset for either driver', function () {
    expect(oracleSrcConnectionConfig(['ORACLE_SRC_DRIVER' => 'oracle', 'ORACLE_SRC_CHARSET' => 'WE8MSWIN1252'])['charset'])
        ->toBe('WE8MSWIN1252')
        ->and(oracleSrcConnectionConfig(['ORACLE_SRC_DRIVER' => 'mysql', 'ORACLE_SRC_CHARSET' => 'utf8'])['charset'])
        ->toBe('utf8');
});

it('exposes the Oracle addressing and schema keys yajra reads', function () {
    $config = oracleSrcConnectionConfig([
        'ORACLE_SRC_DRIVER' => 'oracle',
        'ORACLE_SRC_TNS' => '(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=db)(PORT=1521))(CONNECT_DATA=(SERVICE_NAME=PKG)))',
        'ORACLE_SRC_SERVICE_NAME' => 'NASIRPKG',
        'ORACLE_SRC_SCHEMA_PREFIX' => 'NASIRPKG',
        'ORACLE_SRC_SERVER_VERSION' => '12c',
    ]);

    expect($config['tns'])->toContain('SERVICE_NAME=PKG')
        ->and($config['service_name'])->toBe('NASIRPKG')
        ->and($config['prefix_schema'])->toBe('NASIRPKG')
        ->and($config['server_version'])->toBe('12c');
});

it('defaults the Oracle keys to empty, and server_version to 11g', function () {
    // 11g, because the mill's instance is Oracle 11.2.0.1.0 (oracle:ping's banner, 2026-10-31): a 12c
    // setting compiles OFFSET/FETCH NEXT, which no server before 12.1 can parse. This assertion is the
    // guard against someone bumping the default blind.
    $config = oracleSrcConnectionConfig(['ORACLE_SRC_DRIVER' => 'oracle']);

    expect($config['tns'])->toBe('')
        ->and($config['service_name'])->toBe('')
        ->and($config['prefix_schema'])->toBe('')
        ->and($config['server_version'])->toBe('11g');
});

it('keeps Oracle column names in their own case, so the source models read the same on both drivers', function () {
    // yajra's OracleConnector defaults to PDO::CASE_LOWER. Left alone, every source model would get
    // lower-case attribute keys from the real instance and upper-case ones from the stand-in, so
    // `$row->LOT_NUM` would be correct in the test suite and silently null against the mill's ERP.
    $oracle = oracleSrcConnectionConfig(['ORACLE_SRC_DRIVER' => 'oracle']);

    expect($oracle['options'])->toBe([PDO::ATTR_CASE => PDO::CASE_NATURAL]);

    // The stand-in keeps the MySQL options it had: PDO::ATTR_CASE is not a MySQL problem.
    expect(oracleSrcConnectionConfig(['ORACLE_SRC_DRIVER' => 'mysql'])['options'])
        ->not->toHaveKey(PDO::ATTR_CASE);
});

it('keeps the MySQL stand-in shape intact', function () {
    $config = oracleSrcConnectionConfig(['ORACLE_SRC_DRIVER' => 'mysql']);

    expect($config['driver'])->toBe('mysql')
        ->and($config['collation'])->toBe('utf8mb4_unicode_ci')
        ->and($config['prefix'])->toBe('')
        ->and($config['prefix_indexes'])->toBeTrue()
        ->and($config['strict'])->toBeTrue()
        ->and($config['engine'])->toBeNull()
        ->and($config['foreign_key_constraints'])->toBeFalse();
});

it('treats only the stand-in drivers as mocked', function () {
    // The seedMock() side of this is covered in OracleSourceTest; here it is assertMocked() itself.
    foreach (['mysql', 'mariadb', 'sqlite'] as $driver) {
        config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => $driver]);
        expect(OracleSourceConnection::isMocked())->toBeTrue("[{$driver}] is a stand-in");
        OracleSourceConnection::assertMocked();
    }

    config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => 'oracle']);

    expect(OracleSourceConnection::isMocked())->toBeFalse()
        ->and(fn () => OracleSourceConnection::assertMocked())->toThrow(RuntimeException::class);
});
