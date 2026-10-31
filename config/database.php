<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

// Resolved once, because the `oracle_src` connection below is shaped differently for a real Oracle instance
// than for the MySQL stand-in it defaults to (see the comment above that connection).
$oracleSrcDriver = env('ORACLE_SRC_DRIVER', env('DB_CONNECTION', 'mysql'));

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         * The Oracle source (docs/planning/findings.md). Oracle owns the transactions; this application owns
         * status, timeline and user actions, so the app NEVER writes here — the models on this connection
         * refuse every write.
         *
         * No Oracle instance exists yet, so this defaults to a local stand-in: the same server, credentials and
         * schema as the app connection, where the Oracle-shaped tables (PROCESSING_LOTS, GREIGE_ISSUE_M/_D,
         * PROCESSING_CONTRACT_D and the code masters) sit alongside the app's own — their names cannot collide.
         *
         * The stand-in schema is created by the ordinary `php artisan migrate`
         * (2026_09_24_000400_create_mock_oracle_source_tables), which creates nothing at all when this
         * connection is a real Oracle instance or when APP_ENV=production, and skips any table that already
         * exists. There is no `oracle:mock-migrate` command; an earlier version of this comment claimed one.
         *
         * Pointing this at a separate schema, or at the real instance, is a pure .env change:
         *
         *   ORACLE_SRC_DATABASE=fabric_oracle_src            # a separate MySQL schema
         *
         *   ORACLE_SRC_DRIVER=oracle                         # the real instance: needs ext-oci8 and
         *   ORACLE_SRC_HOST=... ORACLE_SRC_PORT=1521         # yajra/laravel-oci8, which registers the
         *   ORACLE_SRC_USERNAME=... ORACLE_SRC_PASSWORD=...  # `oracle` driver with Laravel.
         *
         * How the instance is addressed, in yajra's order of precedence:
         *   - ORACLE_SRC_TNS — a full TNS descriptor. Non-empty, it wins over host/port/database entirely.
         *   - ORACLE_SRC_SERVICE_NAME — a *service name* (connects as //host:port/service).
         *   - ORACLE_SRC_DATABASE — a *SID*. This is what plain `database` means to yajra, so a service name
         *     supplied there fails with ORA-12514 ("listener does not currently know of service requested");
         *     move it to ORACLE_SRC_SERVICE_NAME.
         *
         * ORACLE_SRC_CHARSET is the Oracle client character set (passed straight to oci_connect), so it
         * defaults to AL32UTF8 when the driver is `oracle` — `utf8mb4` is a MySQL name and Oracle rejects it.
         *
         * ORACLE_SRC_SCHEMA_PREFIX (yajra's `prefix_schema`) is the owning schema to qualify into when the
         * login user is not the owner of the objects it reads. **The source models do not use it and it
         * should normally stay empty.** The mill's data lives in two schemas at once — transactions in
         * PRODUCTION, code masters in ERP — so a single global prefix cannot address it, and each model
         * names its own owner instead (App\Modules\OracleSync\Source\SourceModel). Setting it is also not
         * free: yajra prepends the prefix to *aliased column references* as well, so with it set a join
         * compiles to `"PRODUCTION"."D"."GI_MAIN_ID"` and fails with ORA-00904. The key stays here for the
         * general case of a single-schema source, and because `oracle:ping` suggests it when a table is
         * unreadable.
         *
         * ORACLE_SRC_SERVER_VERSION (yajra's `server_version`) decides how LIMIT/OFFSET is compiled: `12c`
         * emits `OFFSET … FETCH NEXT`, `11g` a ROWNUM subquery. It must stay `11g` here: the mill's instance
         * is **Oracle Database 11g Enterprise Edition Release 11.2.0.1.0** (`oracle:ping`'s banner line,
         * 2026-10-31; an earlier reading of a different instance reported 10.2.0.3.0), and
         * `OFFSET … FETCH NEXT` does not exist before 12.1, so a `12c` setting makes every paginated read
         * fail with a syntax error. Do not "modernise" this default without first checking `oracle:ping`'s
         * banner line against the server it will talk to.
         *
         * Whatever this points at, the application only ever reads: every model on this connection extends
         * SourceModel, which refuses save() and delete() outright (App\Modules\OracleSync\Source\SourceModel),
         * and `php artisan oracle:ping` is the read-only way to check what a configured instance exposes.
         */
        'oracle_src' => [
            'driver' => $oracleSrcDriver,
            'url' => env('ORACLE_SRC_URL', env('DB_URL')),
            'host' => env('ORACLE_SRC_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('ORACLE_SRC_PORT', env('DB_PORT', '3306')),
            'database' => env('ORACLE_SRC_DATABASE', env('DB_DATABASE', 'laravel')),
            'username' => env('ORACLE_SRC_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('ORACLE_SRC_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('ORACLE_SRC_SOCKET', env('DB_SOCKET', '')),
            // Read by yajra/laravel-oci8 only; harmless and ignored by the MySQL stand-in.
            'tns' => env('ORACLE_SRC_TNS', ''),
            'service_name' => env('ORACLE_SRC_SERVICE_NAME', ''),
            'prefix_schema' => env('ORACLE_SRC_SCHEMA_PREFIX', ''),
            'server_version' => env('ORACLE_SRC_SERVER_VERSION', '11g'),
            'charset' => env('ORACLE_SRC_CHARSET', $oracleSrcDriver === 'oracle' ? 'AL32UTF8' : env('DB_CHARSET', 'utf8mb4')),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'foreign_key_constraints' => false,
            // PDO::ATTR_CASE matters more here than it looks. yajra's OracleConnector defaults to
            // CASE_LOWER, so a real instance would hand every source model lower-case attribute keys while
            // the MySQL/SQLite stand-in returns the upper-case names Oracle actually stores: `$row->LOT_NUM`
            // would be correct against the mock and silently null against the mill's ERP. CASE_NATURAL
            // keeps Oracle's own spelling, so both behave the same. OracleSchemaBrowser is unaffected — it
            // aliases every dictionary column to a quoted lower-case name, which no case mode rewrites.
            'options' => $oracleSrcDriver === 'oracle'
                ? [PDO::ATTR_CASE => PDO::CASE_NATURAL]
                : (extension_loaded('pdo_mysql') ? array_filter([
                    Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                ]) : []),
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
