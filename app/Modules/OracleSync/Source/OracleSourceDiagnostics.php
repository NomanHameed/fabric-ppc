<?php

namespace App\Modules\OracleSync\Source;

use Throwable;

/**
 * Shared, read-only diagnosis of the `oracle_src` connection.
 *
 * `oracle:ping` (console) and the Oracle source schema browser (web) have to answer the same four
 * questions — can this machine open the driver at all, what did Oracle actually say, which ORA- code was
 * it, and what should the operator change — so the logic lives here once instead of twice.
 *
 * The console wants finished English sentences; the screen wants keys it can translate. Both are served
 * from the same detection: `missingRequirements()` / `hintCodes()` return codes, and
 * `driverUnavailable()` / `connectionHints()` render the console's wording from those same codes.
 *
 * Nothing here touches the database. It only reads configuration, loaded extensions and exception text.
 */
final class OracleSourceDiagnostics
{
    public const REQUIREMENT_OCI8 = 'oci8';

    public const REQUIREMENT_YAJRA = 'yajra';

    public const HINT_SERVICE_UNKNOWN = 'service_unknown';

    public const HINT_SID_UNKNOWN = 'sid_unknown';

    public const HINT_BAD_CREDENTIALS = 'bad_credentials';

    public const HINT_LISTENER_UNREACHABLE = 'listener_unreachable';

    public const HINT_UNSUPPORTED_DRIVER = 'unsupported_driver';

    /** ORA-00942 / ORA-01031 against the data dictionary: the login simply may not read it. */
    public const HINT_NO_PRIVILEGE = 'no_privilege';

    /**
     * What this machine is missing before the configured driver can even be opened.
     *
     * Without this check, `oracle` on a machine with no oci8 dies inside Laravel's connection factory
     * with "Unsupported driver [oracle]", which says nothing about what to install.
     *
     * @return list<string> requirement codes, empty when the driver can be attempted
     */
    public static function missingRequirements(string $driver): array
    {
        if ($driver !== 'oracle') {
            return [];
        }

        $missing = [];
        if (! extension_loaded('oci8')) {
            $missing[] = self::REQUIREMENT_OCI8;
        }
        if (! class_exists('Yajra\Oci8\Oci8ServiceProvider')) {
            $missing[] = self::REQUIREMENT_YAJRA;
        }

        return $missing;
    }

    /** The console sentence for a driver that cannot be opened, or null when it can be attempted. */
    public static function driverUnavailable(string $driver): ?string
    {
        $missing = self::missingRequirements($driver);

        if ($missing === []) {
            return null;
        }

        $labels = [
            self::REQUIREMENT_OCI8 => 'the PHP oci8 extension (needs the Oracle Instant Client, then `pecl install oci8`)',
            self::REQUIREMENT_YAJRA => 'the yajra/laravel-oci8 package (`composer require yajra/laravel-oci8`), which registers the [oracle] driver with Laravel',
        ];

        return 'ORACLE_SRC_DRIVER=oracle, but this machine is missing '
            .implode(' and ', array_map(fn (string $code): string => $labels[$code], $missing))
            .'. Install them, or set ORACLE_SRC_DRIVER=mysql to use the local stand-in.';
    }

    /**
     * The part of the message worth reading: one line, without the SQL and connection Laravel appends.
     *
     * oci8 errors arrive as several lines whose first is only "Error Code : 942", so the ORA- line is
     * preferred when there is one — that is the line that says what went wrong.
     */
    public static function firstLine(Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_match('/ORA-\d{5}:[^\r\n]*/', $message, $matches) === 1
            ? $matches[0]
            : trim(strtok($message, "\n") ?: $message);
        $message = trim((string) strstr($message.' (Connection: ', ' (Connection: ', true));

        if ($message === '') {
            return $e::class;
        }

        return mb_strlen($message) > 160 ? mb_substr($message, 0, 157).'…' : $message;
    }

    public static function oracleCode(Throwable $e): string
    {
        return preg_match('/ORA-\d{5}/', $e->getMessage(), $matches) === 1 ? $matches[0] : '';
    }

    /**
     * Which of the known, actionable failures this is.
     *
     * @return list<string> hint codes, in the order they should be shown
     */
    public static function hintCodes(Throwable $e): array
    {
        $message = $e->getMessage();
        $codes = [];

        if (str_contains($message, 'ORA-12514')) {
            $codes[] = self::HINT_SERVICE_UNKNOWN;
        }
        if (str_contains($message, 'ORA-12505')) {
            $codes[] = self::HINT_SID_UNKNOWN;
        }
        if (str_contains($message, 'ORA-01017')) {
            $codes[] = self::HINT_BAD_CREDENTIALS;
        }
        if (str_contains($message, 'ORA-12541') || str_contains($message, 'ORA-12170')) {
            $codes[] = self::HINT_LISTENER_UNREACHABLE;
        }
        if (str_contains($message, 'Unsupported driver')) {
            $codes[] = self::HINT_UNSUPPORTED_DRIVER;
        }
        if (str_contains($message, 'ORA-00942') || str_contains($message, 'ORA-01031')) {
            $codes[] = self::HINT_NO_PRIVILEGE;
        }

        return $codes;
    }

    /**
     * The console wording for whichever hints apply.
     *
     * @return list<string>
     */
    public static function connectionHints(Throwable $e): array
    {
        $sentences = [
            self::HINT_SERVICE_UNKNOWN => 'ORA-12514: the listener does not know that service. ORACLE_SRC_DATABASE is treated as a'
                .' SID, so if the value you gave it is really a service name, set ORACLE_SRC_SERVICE_NAME instead.',
            self::HINT_SID_UNKNOWN => 'ORA-12505: the listener does not know that SID. Try ORACLE_SRC_SERVICE_NAME instead.',
            self::HINT_BAD_CREDENTIALS => 'ORA-01017: wrong username or password (Oracle passwords can be case-sensitive).',
            self::HINT_LISTENER_UNREACHABLE => 'The listener could not be reached at all: check ORACLE_SRC_HOST, ORACLE_SRC_PORT and the firewall.',
            self::HINT_UNSUPPORTED_DRIVER => 'Laravel has no connector for this driver. For Oracle, install ext-oci8 and yajra/laravel-oci8.',
            self::HINT_NO_PRIVILEGE => 'This login has no SELECT privilege on what was queried. Ask the DBA for the grant (or a synonym).',
        ];

        return array_map(
            fn (string $code): string => $sentences[$code],
            self::hintCodes($e),
        );
    }
}
