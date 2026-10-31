<?php

namespace App\Modules\OracleSync\Source;

use App\Modules\OracleSync\Source\Models\CostCentre;
use App\Modules\OracleSync\Source\Models\GreigeIssueHeader;
use App\Modules\OracleSync\Source\Models\GreigeIssueLine;
use App\Modules\OracleSync\Source\Models\OracleColour;
use App\Modules\OracleSync\Source\Models\OracleDesign;
use App\Modules\OracleSync\Source\Models\OracleOperation;
use App\Modules\OracleSync\Source\Models\OracleParty;
use App\Modules\OracleSync\Source\Models\OracleQuality;
use App\Modules\OracleSync\Source\Models\ProcessingContractHeader;
use App\Modules\OracleSync\Source\Models\ProcessingContractLine;
use App\Modules\OracleSync\Source\Models\ProcessingContractLot;
use App\Modules\OracleSync\Source\Models\ProcessingLot;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Every read of the Oracle source goes through here. Nothing writes.
 *
 * The movement query deliberately does NOT reproduce `PRODUCTION.PROCESSING_LOTS_TRANS` — a view that does
 * exist on the mill's instance (confirmed in ALL_OBJECTS on 2026-10-31, so avoiding it is a choice, not an
 * accident). It joins the PROCESS-* branches on lot number alone, and lot numbers recur across contracts,
 * so each processing row can match several issue rows and its quantity is multiplied (findings.md §5.1). It
 * also leaves out `PC_DTL_ID`, the reliable lot key (§5.2), and hard-codes '01-SEP-26' (§5.3) — the caller
 * passes the range here.
 *
 * The lot-scoped reads below still key on `(LOT_NUM, CONTRACT_NUM)`, because that is what the caller knows.
 * The ledger no longer needs to: `PROCESSING_LOTS.PC_DTL_ID` turned out to be a real, populated column
 * (3 617 of 3 618 rows), so each branch now reads its own contract-line key straight off its own row and
 * the fan-out is structurally impossible rather than merely avoided — there is no join on the PROCESS-*
 * branches at all. Where it was checked against the old subselect (`min(PC_DTL_ID)` from `GREIGE_ISSUE_D`
 * on the same lot and contract) the two agreed on 3 613 of 3 617 rows; the column is the authority and the
 * derivation was the approximation.
 *
 * Table names come from the models, never from literals, so that one mechanism decides whether a read says
 * `"PRODUCTION"."PROCESSING_LOTS"` or plain `PROCESSING_LOTS` (SourceModel::getTable()).
 */
final class OracleSourceReader
{
    /** Transaction types of the signed movement ledger, as the Oracle view names them. */
    public const TRANS_GREIGE_ISSUE = 'GREIGE-ISSUE';

    public const TRANS_PROCESS_USED = 'PROCESS-USED';

    public const TRANS_PROCESS_PRODUCED = 'PROCESS-PRODUCED';

    /**
     * Every processing row for one lot, oldest first — the lot's operation history (findings.md §3.1).
     *
     * @return Collection<int, ProcessingLot>
     */
    public function processingRowsForLot(int $lotNum, ?int $contractNum = null): Collection
    {
        return ProcessingLot::query()
            ->where('LOT_NUM', $lotNum)
            ->when($contractNum !== null, fn ($q) => $q->where('CONTRACT_NUM', $contractNum))
            ->orderBy('DATED')->orderBy('PROCESSING_LOT_ID')
            ->get();
    }

    /**
     * Where the lot is now: the TO_CC_CODE of its most recent processing row, or null when it has none.
     * Deliberately not "the" status — a lot can sit at several cost centres at once (findings.md §3.1), which
     * is what balances() answers.
     */
    public function currentCostCentre(int $lotNum, ?int $contractNum = null): ?int
    {
        $row = ProcessingLot::query()
            ->where('LOT_NUM', $lotNum)
            ->when($contractNum !== null, fn ($q) => $q->where('CONTRACT_NUM', $contractNum))
            ->orderByDesc('DATED')->orderByDesc('PROCESSING_LOT_ID')
            ->first(['TO_CC_CODE']);

        return $row === null ? null : (int) $row->TO_CC_CODE;
    }

    /**
     * Metres of a lot at each cost centre: arrivals (QNTY_PRODUCED where TO_CC_CODE = X) minus departures
     * (QNTY_USED where CC_CODE = X). Whole metres, because Oracle stores whole metres.
     *
     * @return array<int, int> cost centre code => metres
     */
    public function balancesByCostCentre(int $lotNum, ?int $contractNum = null): array
    {
        $balances = [];

        foreach ($this->processingRowsForLot($lotNum, $contractNum) as $row) {
            $from = (int) $row->CC_CODE;
            $to = (int) $row->TO_CC_CODE;
            $balances[$from] = ($balances[$from] ?? 0) - (int) $row->QNTY_USED;
            $balances[$to] = ($balances[$to] ?? 0) + (int) $row->QNTY_PRODUCED;
        }

        ksort($balances);

        return $balances;
    }

    /**
     * The signed movement ledger over a date range — the corrected form of `processing_lots_trans`.
     *
     * Every branch carries `PC_DTL_ID` from its own table: `GREIGE_ISSUE_D` has it, and so does
     * `PROCESSING_LOTS`. No branch joins anything it could be multiplied by. A processing row whose
     * `PC_DTL_ID` is null (one row in 3 560 in the snapshot read) still appears, with a null key, rather
     * than being dropped.
     *
     * @return Collection<int, stdClass>
     */
    public function movements(string $from, string $to): Collection
    {
        $connection = $this->connection();
        $issueHeader = $this->table(GreigeIssueHeader::class);
        $issueLine = $this->table(GreigeIssueLine::class);
        $lots = $this->table(ProcessingLot::class);

        $issues = $connection->table($issueHeader.' as m')
            ->join($issueLine.' as d', 'd.GI_MAIN_ID', '=', 'm.GI_MAIN_ID')
            ->whereBetween('m.DATED', [$from, $to])
            ->selectRaw("'".self::TRANS_GREIGE_ISSUE."' as TRANS_TYPE, m.DATED as DATED, d.LOT_NUM, d.CONTRACT_NUM, d.PC_DTL_ID, m.CC_CODE, d.QNTY as QNTY");

        $used = $connection->table($lots.' as l')
            ->whereBetween('l.DATED', [$from, $to])
            ->selectRaw("'".self::TRANS_PROCESS_USED."' as TRANS_TYPE, l.DATED as DATED, l.LOT_NUM, l.CONTRACT_NUM, l.PC_DTL_ID, l.CC_CODE, -l.QNTY_USED as QNTY");

        $produced = $connection->table($lots.' as l')
            ->whereBetween('l.DATED', [$from, $to])
            ->selectRaw("'".self::TRANS_PROCESS_PRODUCED."' as TRANS_TYPE, l.DATED as DATED, l.LOT_NUM, l.CONTRACT_NUM, l.PC_DTL_ID, l.TO_CC_CODE as CC_CODE, l.QNTY_PRODUCED as QNTY");

        return $this->rows($issues->unionAll($used)->unionAll($produced))
            ->sortBy([fn ($a, $b) => $a->DATED <=> $b->DATED, fn ($a, $b) => $a->LOT_NUM <=> $b->LOT_NUM])
            ->values();
    }

    /**
     * The contract lines carrying one lot, header and detail joined — the lot master.
     *
     * `PROCESSING_CONTRACT_D` is a *detail* table: `CONTRACT_NUM`, `DATED`, `PARTY_CODE` and `REF_NUM` are
     * on `PROCESSING_CONTRACT_M` and have to be joined in (findings.md §4.3 inferred them onto the detail
     * row from a report export that had already flattened the two). The join is on `PC_MAIN_ID`, which is
     * the header's key; `CONTRACT_NUM` is not unique across headers and must never be used as one.
     *
     * **The lot is matched through `PC_DTL_LOTS`, not through `PROCESSING_CONTRACT_D.LOT_NUM`.** That column
     * exists but is populated on only 8 212 of 45 561 lines, and of the 3 557 processing rows that point at
     * a contract line it agrees with the lot on 89 and is null on 3 453 — while `PC_DTL_LOTS` has the
     * `(PC_DTL_ID, LOT_NUM)` pair for all 3 557. Filtering on `d.LOT_NUM` therefore finds almost nothing;
     * this was caught by running the reader against the live instance, not by the mock.
     *
     * `GSM` comes from `PC_DTL_LOTS` too, through a scalar subselect rather than a join, for the same reason
     * the ledger avoids `processing_lots_trans`: that table holds up to 5 rows per `(PC_DTL_ID, LOT_NUM)`,
     * so a join would duplicate the contract line and double-count `QNTY`. A subselect returns one value
     * and can multiply nothing. Where the rows disagree on GSM the lowest is reported, which is a reporting
     * choice, not a fact about the lot.
     *
     * @return Collection<int, stdClass>
     */
    public function contractLinesForLot(int $lotNum, ?int $contractNum = null): Collection
    {
        $lots = $this->table(ProcessingContractLot::class);

        return $this->rows(
            $this->contractQuery($lotNum)
                ->whereExists(fn (QueryBuilder $q) => $q->from($lots.' as pcl')
                    ->selectRaw('1')
                    ->whereColumn('pcl.PC_DTL_ID', 'd.PC_DTL_ID')
                    ->where('pcl.LOT_NUM', $lotNum))
                ->when($contractNum !== null, fn (QueryBuilder $q) => $q->where('m.CONTRACT_NUM', $contractNum))
                ->orderBy('d.PC_DTL_ID')
        );
    }

    /**
     * One contract line by its `PC_DTL_ID` — the reliable lot key (findings.md §5.2).
     *
     * Pass the lot number when it is known: `GSM` is recorded per `(PC_DTL_ID, LOT_NUM)`, so without it the
     * lowest GSM across every lot on the line is reported rather than the one asked about.
     */
    public function contractLine(int $pcDtlId, ?int $lotNum = null): ?stdClass
    {
        return $this->rows($this->contractQuery($lotNum)->where('d.PC_DTL_ID', $pcDtlId))->first();
    }

    /**
     * The code masters, as code => name maps. These are the joins the Excel export used to pre-do for us
     * (findings.md §4) and that a live connection has to do itself.
     *
     * `cost_centres` and `machines` read the *same* table on purpose: machines are cost centres
     * (`ERP.COST_CENTRES`, see CostCentre), but `CC_CODE`/`TO_CC_CODE` and `MACHINE_CODE` are different
     * foreign keys and the callers resolving them are different, so both names stay.
     *
     * `operations` is keyed `CC_CODE:OPERATION_CODE`, not by the bare code: `ERP.PROCESSING_OPERATIONS` has
     * 92 rows and only 89 distinct codes. The cost centre to key on is `PROCESSING_LOTS.CC_CODE`, where the
     * work happened — 3 290 of the 3 553 live rows with an operation resolve that way against only 131 on
     * `TO_CC_CODE` and none on `MACHINE_CODE`. Two caveats a caller must handle:
     *   - even the composite is not quite unique (cost centre 90 uses code 120 for three different names),
     *     so that one entry resolves to whichever row Oracle returns last. The table has no unique key to
     *     do better with, and the alternative is to silently lose two more names;
     *   - **a lookup can miss.** 263 live rows (7.4%) carry a `(CC_CODE, OPERATION_CODE)` pair that is not
     *     in the master at all. Treat an absent key as "operation not named", never as an error.
     *
     * A name can be **null**: 672 of the 3 035 rows in `ERP.QUALITIES` have no `DESCRIPTION`, and of the
     * 149 `Q_CODE`s the processing rows actually use only 117 resolve to one. The code is kept with a null
     * name rather than dropped, so a caller can tell "no name recorded" from "no such code"; the quality's
     * construction columns (REED/PICK/BLEND) are the fallback, see OracleQuality.
     *
     * @return array<string, array<array-key, string|null>>
     */
    public function masters(): array
    {
        $costCentres = CostCentre::query()->pluck(CostCentre::NAME_COLUMN, 'CC_CODE')->all();

        return [
            'cost_centres' => $costCentres,
            'machines' => $costCentres,
            'operations' => $this->operations(),
            'designs' => OracleDesign::query()->pluck(OracleDesign::NAME_COLUMN, 'DESIGN_CODE')->all(),
            'colours' => OracleColour::query()->pluck(OracleColour::NAME_COLUMN, 'COLOUR_CODE')->all(),
            'parties' => OracleParty::query()->pluck(OracleParty::NAME_COLUMN, 'PARTY_CODE')->all(),
            'qualities' => OracleQuality::query()->pluck(OracleQuality::NAME_COLUMN, 'Q_CODE')->all(),
        ];
    }

    /** The key `masters()['operations']` is addressed by, for a row that carries both codes. */
    public function operationKey(int|string $costCentre, int|string $operationCode): string
    {
        return $costCentre.':'.$operationCode;
    }

    /** @return array<string, string|null> */
    private function operations(): array
    {
        $operations = [];

        foreach (OracleOperation::query()->get(['CC_CODE', 'OPERATION_CODE', OracleOperation::NAME_COLUMN]) as $row) {
            $name = $row->{OracleOperation::NAME_COLUMN};
            $operations[$this->operationKey((int) $row->CC_CODE, (int) $row->OPERATION_CODE)]
                = is_string($name) ? $name : null;
        }

        return $operations;
    }

    /**
     * Header joined to detail, with GSM subselected from PC_DTL_LOTS for `$lotNum` when one is given.
     *
     * Note that `d.LOT_NUM` is selected as it stands — usually null — because it is a real column and
     * hiding it would be its own kind of lie. The lot a caller asked about is the one it passed in.
     */
    private function contractQuery(?int $lotNum): QueryBuilder
    {
        $connection = $this->connection();

        // A correlated scalar subselect, built through the query builder so the table name is wrapped by
        // the grammar rather than pasted into SQL text. One value per row: it cannot multiply the line.
        $gsm = $connection->table($this->table(ProcessingContractLot::class).' as pl')
            ->selectRaw('min(pl.GSM)')
            ->whereColumn('pl.PC_DTL_ID', 'd.PC_DTL_ID')
            ->when($lotNum !== null, fn (QueryBuilder $q) => $q->where('pl.LOT_NUM', $lotNum));

        return $connection->table($this->table(ProcessingContractHeader::class).' as m')
            ->join($this->table(ProcessingContractLine::class).' as d', 'd.PC_MAIN_ID', '=', 'm.PC_MAIN_ID')
            ->selectRaw(
                'm.PC_MAIN_ID, m.CONTRACT_NUM, m.DATED, m.PARTY_CODE, m.REF_NUM,'
                .' d.PC_DTL_ID, d.LOT_NUM, d.Q_CODE, d.PROCESS_TYPE, d.DESIGN_CODE, d.VERSION_CODE,'
                .' d.COLOUR_CODE, d.G_WIDTH, d.F_WIDTH, d.QNTY'
            )
            ->selectSub($gsm, 'GSM');
    }

    /**
     * The rows of one query, with their column names in upper case.
     *
     * yajra's connector sets `PDO::ATTR_CASE => PDO::CASE_LOWER`, which config/database.php overrides to
     * CASE_NATURAL so that Oracle's own upper-case names survive and `$row->LOT_NUM` means the same thing
     * on Oracle as on the stand-in. This normalisation is the belt to that braces: it costs one array walk
     * and it means a raw read here cannot depend on a PDO attribute set three files away.
     *
     * @return Collection<int, stdClass>
     */
    private function rows(QueryBuilder $query): Collection
    {
        return $query->get()->map(static function (mixed $row): stdClass {
            /** @var array<string, mixed> $attributes */
            $attributes = array_change_key_case((array) $row, CASE_UPPER);

            return (object) $attributes;
        })->values();
    }

    private function connection(): Connection
    {
        return DB::connection(OracleSourceConnection::NAME);
    }

    /**
     * The table name a model reads from — owner-qualified on Oracle, bare on the stand-in.
     *
     * @param  class-string<SourceModel>  $model
     */
    private function table(string $model): string
    {
        return (new $model)->getTable();
    }
}
