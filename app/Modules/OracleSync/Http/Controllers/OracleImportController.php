<?php

namespace App\Modules\OracleSync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\OracleSync\Exceptions\InvalidOracleFile;
use App\Modules\OracleSync\Jobs\CommitOracleImport;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Models\Lot;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OracleImportController extends Controller
{
    private const TABS = ['error', 'new', 'changed', 'missing', 'unchanged'];

    public function __construct(private readonly ImportPreviewService $preview) {}

    public function index(Request $request): Response
    {
        return Inertia::render('oracle/Imports', [
            'imports' => OracleImport::query()->with('uploader')->latest('id')->limit(50)->get()->map(fn ($i) => $this->summary($i)),
            'canRun' => $request->user()->can('oracle.import.run'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:xlsx']]);
        $file = $request->file('file');
        $stored = $file->storeAs('oracle-imports', Str::uuid().'.xlsx', 'local');

        try {
            $import = $this->preview->preview(
                new ExcelUploadSource(Storage::disk('local')->path($stored), $file->getClientOriginalName()),
                $request->user(),
            );
        } catch (InvalidOracleFile $e) {
            Storage::disk('local')->delete($stored);
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()->route('oracle.imports.show', $import);
    }

    public function show(Request $request, OracleImport $import): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : $this->defaultTab($import);
        $rows = $import->rows()->where('action', $tab)->orderBy('id')->limit(2000)->get();
        $lots = Lot::query()->with(['contract.party', 'quality'])->whereIn('id', $rows->pluck('lot_id')->filter())->get()->keyBy('id');

        return Inertia::render('oracle/ImportPreview', [
            'import' => $this->summary($import->load('uploader')) + [
                'unresolved_parties' => $import->unresolved_parties ?? [], 'error_message' => $import->error_message,
            ],
            'tab' => $tab,
            'rows' => $rows->map(function ($r) use ($lots) {
                $p = $r->payload;
                $lot = $r->lot_id ? $lots->get($r->lot_id) : null;

                return [
                    'id' => $r->id, 'row_no' => $r->row_no, 'oracle_line_id' => $r->oracle_line_id, 'action' => $r->action,
                    'message' => $r->message ? Str::after($r->message, ':') : null, 'diff' => $r->diff,
                    'lot_no' => $p['lot_no'] ?? $lot?->lot_no, 'po_no' => $p['po_no'] ?? $lot?->contract->po_no,
                    'party' => $p['party_name'] ?? $lot?->contract->party->name, 'design' => $p['design'] ?? $lot?->design,
                    'colour' => $p['colour'] ?? $lot?->colour, 'quality' => $p['quality_description'] ?? $lot?->quality->description,
                    'greige_qty' => $p['greige_qty'] ?? ($lot ? (float) $lot->greige_qty : null),
                ];
            }),
            'canRun' => $request->user()->can('oracle.import.run'),
        ]);
    }

    public function resolveParty(Request $request, OracleImport $import, int $code): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['link', 'create'])],
            'party_id' => ['required_if:mode,link', 'nullable', 'integer', 'exists:parties,id'],
            'type' => ['required_if:mode,create', 'nullable', Rule::in(['brand', 'local'])],
        ]);

        try {
            $this->preview->resolveParty($import, $code, $data);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['import' => $e->getMessage()]);
        }

        return back();
    }

    public function commit(Request $request, OracleImport $import): RedirectResponse
    {
        if ($import->status !== 'previewed' || $import->error_count > 0) {
            throw ValidationException::withMessages(['import' => __($import->error_count > 0 ? 'oracle.errors.has_errors' : 'oracle.errors.not_previewed')]);
        }
        CommitOracleImport::dispatch($import->id, $request->user()->id);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('oracle.status.committing')]);

        return back();
    }

    public function discard(OracleImport $import): RedirectResponse
    {
        $this->preview->discard($import);

        return redirect()->route('oracle.imports.index');
    }

    private function defaultTab(OracleImport $import): string
    {
        if ($import->error_count > 0) {
            return 'error';
        }
        foreach (['new', 'changed', 'missing', 'unchanged'] as $t) {
            if ($import->{"{$t}_count"} > 0) {
                return $t;
            }
        }

        return 'new';
    }

    private function summary(OracleImport $i): array
    {
        return [
            'id' => $i->id, 'label' => $i->label, 'source' => $i->source, 'status' => $i->status, 'uploader' => $i->uploader?->name,
            'created_at' => $i->created_at?->format('d-m-Y H:i'), 'committed_at' => $i->committed_at?->format('d-m-Y H:i'),
            'new_count' => $i->new_count, 'changed_count' => $i->changed_count, 'unchanged_count' => $i->unchanged_count,
            'missing_count' => $i->missing_count, 'error_count' => $i->error_count,
        ];
    }
}
