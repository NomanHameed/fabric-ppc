<?php

namespace App\Modules\OracleSync\Source;

use App\Modules\OracleSync\Source\Models\CostCentre;
use App\Modules\OracleSync\Source\Models\OracleColour;
use App\Modules\OracleSync\Source\Models\OracleDesign;
use App\Modules\OracleSync\Source\Models\OracleMachine;
use App\Modules\OracleSync\Source\Models\OracleOperation;
use App\Modules\OracleSync\Source\Models\OracleParty;
use App\Modules\OracleSync\Source\Models\OracleQuality;
use App\Modules\OracleSync\Source\Models\ProcessingLot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every read of the Oracle source goes through here. Nothing writes.
 *
 * The movement query deliberately does NOT reproduce `processing_lots_trans` as supplied. That view joins the
 * PROCESS-* branches on lot number alone, and lot numbers recur across contracts, so each processing row can
 * match several issue rows and its quantity is multiplied (findings.md §5.1). The queries below key on
 * (LOT_NUM, CONTRACT_NUM) and expose PC_DTL_ID, the reliable lot key the view leaves out (§5.2). There is also
 * no hard-coded '01-SEP-26' here (§5.3): the caller passes the range.
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
     * Issue rows carry PC_DTL_ID from GREIGE_ISSUE_D directly; processing rows are keyed back to a contract
     * line on (LOT_NUM, CONTRACT_NUM), which is what stops the fan-out. A processing row whose lot number and
     * contract number match no issue line still appears, with a null PC_DTL_ID, rather than being dropped.
     *
     * @return Collection<int, \stdClass>
     */
    public function movements(string $from, string $to): Collection
    {
        $connection = DB::connection(OracleSourceConnection::NAME);

        // One scalar subselect instead of a join: a processing row can never be multiplied by it, which is
        // precisely the defect in the supplied view.
        $pcDtlId = '(select min(g.PC_DTL_ID) from GREIGE_ISSUE_D g'
            .' where g.LOT_NUM = l.LOT_NUM and g.CONTRACT_NUM = l.CONTRACT_NUM) as PC_DTL_ID';

        $issues = $connection->table('GREIGE_ISSUE_M as m')
            ->join('GREIGE_ISSUE_D as d', 'd.GI_MAIN_ID', '=', 'm.GI_MAIN_ID')
            ->whereBetween('m.DATED', [$from, $to])
            ->selectRaw("'".self::TRANS_GREIGE_ISSUE."' as TRANS_TYPE, m.DATED as DATED, d.LOT_NUM, d.CONTRACT_NUM, d.PC_DTL_ID, m.CC_CODE, d.QNTY as QNTY");

        $used = $connection->table('PROCESSING_LOTS as l')
            ->whereBetween('l.DATED', [$from, $to])
            ->selectRaw("'".self::TRANS_PROCESS_USED."' as TRANS_TYPE, l.DATED as DATED, l.LOT_NUM, l.CONTRACT_NUM, {$pcDtlId}, l.CC_CODE, -l.QNTY_USED as QNTY");

        $produced = $connection->table('PROCESSING_LOTS as l')
            ->whereBetween('l.DATED', [$from, $to])
            ->selectRaw("'".self::TRANS_PROCESS_PRODUCED."' as TRANS_TYPE, l.DATED as DATED, l.LOT_NUM, l.CONTRACT_NUM, {$pcDtlId}, l.TO_CC_CODE as CC_CODE, l.QNTY_PRODUCED as QNTY");

        return collect($issues->unionAll($used)->unionAll($produced)->get())
            ->sortBy([fn ($a, $b) => $a->DATED <=> $b->DATED, fn ($a, $b) => $a->LOT_NUM <=> $b->LOT_NUM])
            ->values();
    }

    /**
     * The code masters, as code => name maps. These are the joins the Excel export used to pre-do for us
     * (findings.md §4) and that a live connection has to do itself.
     *
     * @return array<string, array<int, string>>
     */
    public function masters(): array
    {
        return [
            'cost_centres' => CostCentre::query()->pluck('CC_NAME', 'CC_CODE')->all(),
            'machines' => OracleMachine::query()->pluck('MACHINE_NAME', 'MACHINE_CODE')->all(),
            'operations' => OracleOperation::query()->pluck('OPERATION_NAME', 'OPERATION_CODE')->all(),
            'designs' => OracleDesign::query()->pluck('DESIGN_NAME', 'DESIGN_CODE')->all(),
            'colours' => OracleColour::query()->pluck('COLOUR_NAME', 'COLOUR_CODE')->all(),
            'parties' => OracleParty::query()->pluck('PARTY_NAME', 'PARTY_CODE')->all(),
            'qualities' => OracleQuality::query()->pluck('QUALITY_NAME', 'Q_CODE')->all(),
        ];
    }
}
