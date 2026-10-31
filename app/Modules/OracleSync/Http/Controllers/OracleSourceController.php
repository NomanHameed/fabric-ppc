<?php

namespace App\Modules\OracleSync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\OracleSync\Source\OracleSchemaBrowser;
use App\Modules\OracleSync\Source\OracleSourceDiagnostics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The Oracle source schema browser: the one screen in this application that talks to the client's live
 * ERP, and it only ever reads the data dictionary.
 *
 * It exists because the eleven tables the importer was written against are not in the instance the mill
 * supplied — every one answers ORA-00942 — so the real object names have to be discoverable from inside
 * the app before anything can be mapped to them.
 *
 * Every failure mode here is realistic (no oci8, listener down, wrong password, a login with no
 * dictionary privileges) and none of them may become a 500: the screen renders with a message that says
 * what Oracle said and what to change.
 */
class OracleSourceController extends Controller
{
    public function __construct(private readonly OracleSchemaBrowser $browser) {}

    public function index(Request $request): Response
    {
        return $this->screen($request, null, null);
    }

    public function show(Request $request, string $owner, string $object): Response
    {
        return $this->screen($request, $owner, $object);
    }

    /**
     * The whole screen, with or without a described object.
     *
     * The list is rendered either way, so the operator who followed a stale link still has somewhere to
     * go, and the object is resolved against the dictionary first — an unknown name is a 404 and never a
     * query naming it.
     */
    private function screen(Request $request, ?string $owner, ?string $object): Response
    {
        $filters = $this->filters($request);
        $connection = $this->browser->connectionSummary();

        // Checked before anything opens a connection: on a machine without oci8 the driver cannot even be
        // resolved, and Laravel's own failure ("Unsupported driver [oracle]") says nothing useful.
        $missing = $this->browser->missingRequirements();
        if ($missing !== []) {
            return $this->render($filters, $connection, null, [], $this->requirementError($missing));
        }

        try {
            $selected = $object === null ? null : $this->browser->describe((string) $owner, $object);

            if ($object !== null && $selected === null) {
                abort(404);
            }

            $owners = $this->browser->owners();
            $objects = $this->browser->objects(
                $filters['q'],
                $filters['owner'],
                $filters['type'],
                $filters['page'],
            );
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            // The dictionary is unreachable or ungranted. That is information, not an exception page.
            report($e);

            return $this->render($filters, $connection, null, [], $this->connectionError($e));
        }

        return $this->render($filters, $connection, $selected, $owners, null, $objects);
    }

    /**
     * @param  array{q: string|null, owner: string|null, type: string|null, page: int}  $filters
     * @param  array<string, mixed>  $connection
     * @param  array<string, mixed>|null  $selected
     * @param  list<array{owner: string, objects: int}>  $owners
     * @param  array<string, mixed>|null  $error
     * @param  array<string, mixed>|null  $objects
     */
    private function render(array $filters, array $connection, ?array $selected, array $owners, ?array $error, ?array $objects = null): Response
    {
        return Inertia::render('oracle/SourceBrowser', [
            'connection' => $connection,
            'filters' => $filters,
            'defaultOwner' => $this->defaultOwner(),
            'owners' => $owners,
            'types' => OracleSchemaBrowser::TYPES,
            'objects' => $objects ?? ['rows' => [], 'total' => 0, 'page' => $filters['page'], 'per_page' => OracleSchemaBrowser::PER_PAGE, 'pages' => 1],
            'selected' => $selected,
            'error' => $error,
        ]);
    }

    /**
     * The filters, validated rather than trusted. `owner` absent means "open on the login's own schema";
     * `owner=` (empty) means "every owner" — the 703 SYS tables are reachable, just never the default.
     *
     * @return array{q: string|null, owner: string|null, type: string|null, page: int}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:'.OracleSchemaBrowser::MAX_IDENTIFIER],
            'owner' => ['nullable', 'string', 'max:'.OracleSchemaBrowser::MAX_IDENTIFIER, 'regex:/^[A-Za-z0-9_$#]+$/'],
            'type' => ['nullable', Rule::in(OracleSchemaBrowser::TYPES)],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $q = isset($data['q']) && is_string($data['q']) && trim($data['q']) !== '' ? trim($data['q']) : null;
        $owner = isset($data['owner']) && is_string($data['owner']) && $data['owner'] !== '' ? $data['owner'] : null;
        $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : null;

        if ($owner === null && ! $request->has('owner')) {
            $owner = $this->defaultOwner();
        }

        return [
            'q' => $q,
            'owner' => $owner,
            'type' => $type,
            'page' => isset($data['page']) ? max(1, (int) $data['page']) : 1,
        ];
    }

    /** Never allowed to be the reason the screen fails: a missing setting just means "no default owner". */
    private function defaultOwner(): ?string
    {
        try {
            $owner = $this->browser->defaultOwner();
        } catch (Throwable) {
            return null;
        }

        return $owner === '' ? null : $owner;
    }

    /**
     * @param  list<string>  $missing
     * @return array{message: string, detail: string|null, code: string|null, hints: list<string>}
     */
    private function requirementError(array $missing): array
    {
        return [
            'message' => __('oracle.source.errors.driver_unavailable'),
            'detail' => null,
            'code' => null,
            'hints' => array_map(
                fn (string $code): string => (string) __('oracle.source.requirements.'.$code),
                $missing,
            ),
        ];
    }

    /**
     * @return array{message: string, detail: string|null, code: string|null, hints: list<string>}
     */
    private function connectionError(Throwable $e): array
    {
        $codes = OracleSourceDiagnostics::hintCodes($e);
        $oraCode = OracleSourceDiagnostics::oracleCode($e);

        $message = match (true) {
            in_array(OracleSourceDiagnostics::HINT_NO_PRIVILEGE, $codes, true) => __('oracle.source.errors.no_dictionary'),
            in_array(OracleSourceDiagnostics::HINT_BAD_CREDENTIALS, $codes, true) => __('oracle.source.errors.credentials'),
            in_array(OracleSourceDiagnostics::HINT_LISTENER_UNREACHABLE, $codes, true),
            in_array(OracleSourceDiagnostics::HINT_SERVICE_UNKNOWN, $codes, true),
            in_array(OracleSourceDiagnostics::HINT_SID_UNKNOWN, $codes, true) => __('oracle.source.errors.unreachable'),
            default => __('oracle.source.errors.unavailable'),
        };

        return [
            'message' => (string) $message,
            'detail' => OracleSourceDiagnostics::firstLine($e),
            'code' => $oraCode === '' ? null : $oraCode,
            'hints' => array_map(
                fn (string $code): string => (string) __('oracle.source.hints.'.$code),
                $codes,
            ),
        ];
    }
}
