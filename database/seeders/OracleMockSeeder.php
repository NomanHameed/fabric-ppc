<?php

namespace Database\Seeders;

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
use App\Modules\OracleSync\Source\OracleSourceConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Rows for the mocked Oracle source. Does nothing at all unless `oracle_src` is a local stand-in — the real
 * instance is read-only to this application, and SourceModel::seedMock() refuses anyway.
 *
 * The data follows the real schema (docs/planning/findings.md, §9 for the 2026-10-31 verification):
 * quantities are whole metres, `CC_CODE` → `TO_CC_CODE` is the movement, and the fixtures that exist to
 * make known defects reproducible are all still here:
 *
 *   - **lot 640900 appears under two different contracts** (§5.1), so the `processing_lots_trans` fan-out
 *     can be demonstrated and the corrected reader proved against it;
 *   - **`PC_DTL_LOTS` carries several rows per contract line**, with differing GSM, so a reader that joined
 *     it instead of subselecting would visibly double-count;
 *   - **one `OPERATION_CODE` is reused under two cost centres**, as code 120 is on the live instance, so a
 *     master keyed on the bare code would visibly lose a name;
 *   - one processing row in twelve has a null `OPERATION_CODE`, which Oracle allows (§2.3).
 */
class OracleMockSeeder extends Seeder
{
    /** CC_CODE => name — the mill's departments, as cost centres. */
    public const COST_CENTRES = [
        82 => 'GREIGE STORE',
        101 => 'SINGEING',
        102 => 'KIER BLEACHING',
        103 => 'JIGGER DYEING',
        104 => 'MERCERIZING',
        105 => 'BATCHING - STENTER',
        106 => 'PRINTING',
        107 => 'CURING',
        108 => 'DRYING',
        109 => 'FINISH - STENTER',
        110 => 'SANFORIZING / CALANDER',
        111 => 'ROLLING',
        112 => 'STITCHING',
        199 => 'FINISHED GOODS STORE',
    ];

    /**
     * Machines are cost centres too (`ERP.COST_CENTRES` with `TYPE = 'MACHINE'`): there is no machine
     * master on the instance. They keep their own code range here only so the fixture stays readable —
     * on the live instance machine and department codes share one numbering.
     *
     * MACHINE_CODE => [name, the department it sits in]
     */
    public const MACHINES = [
        201 => ['STENTER NO. 1', 109], 202 => ['STENTER NO. 2', 109], 203 => ['SANFORIZER', 110],
        204 => ['DRYING #01', 108], 205 => ['DRYING #02', 108], 206 => ['JUMBO', 108],
        207 => ['SINGEING RANGE', 101], 208 => ['MERCERIZER', 104], 209 => ['DIGITAL PRINTER', 106],
        210 => ['JIGGER DYEING', 103],
    ];

    /**
     * OPERATION_CODE => [name, CC_CODE]. Operations are scoped per cost centre, and the cost centre they
     * are scoped to is the row's `CC_CODE` — where the work happened — not `TO_CC_CODE`: of the 3 553
     * live `PROCESSING_LOTS` rows with an operation, 3 290 resolve against `(CC_CODE, OPERATION_CODE)` and
     * only 131 against `(TO_CC_CODE, OPERATION_CODE)` (verified 2026-10-31). The codes used by ROUTE below
     * are therefore registered under that route step's source cost centre.
     */
    public const OPERATIONS = [
        301 => ['SINGING', 82], 302 => ['SCOUR+BLEACH', 101], 303 => ['DESIZE+BLEACH', 103],
        304 => ['SINGLE BLEACH', 103], 305 => ['MERCERIZING', 103], 306 => ['REACTIVE DYE', 104],
        307 => ['PIGMENT PADDING', 105], 308 => ['REACTIVE PRINTING', 106], 309 => ['REACTIVE CURE', 107],
        310 => ['WASHING', 103], 311 => ['DRY', 103], 312 => ['DYE FINISH FOR SANFORIZING', 108],
        313 => ['PRINT FINISH', 109], 314 => ['WHITE FINISH FOR SANFORIZING', 109], 315 => ['SANFORIZE', 109],
        316 => ['ROLLING', 110], 317 => ['STITCHING', 112],
    ];

    /**
     * The deliberate collision: `ERP.PROCESSING_OPERATIONS` has 92 rows and 89 distinct OPERATION_CODEs.
     * Code 310 is reused here under a second cost centre with a different name, so anything that keys
     * operations on the bare code is caught by the test suite rather than on the mill's floor.
     *
     * [CC_CODE, OPERATION_CODE, name]
     */
    public const REUSED_OPERATIONS = [
        [106, 310, 'PRINT WASH'],
    ];

    public const DESIGNS = [
        701 => 'PLAIN', 702 => 'DOBBY STRIPE', 703 => 'FLORAL BLOCK', 704 => 'GEOMETRIC',
        705 => 'PAISLEY', 706 => 'CHECKS', 707 => 'DOTTED', 708 => 'ABSTRACT',
    ];

    public const COLOURS = [
        801 => 'NAVY BLUE', 802 => 'IVORY', 803 => 'ROSE PINK', 804 => 'FOREST GREEN',
        805 => 'CHARCOAL', 806 => 'MUSTARD', 807 => 'OFF WHITE', 808 => 'BLACK',
        809 => 'SKY BLUE', 810 => 'MAROON', 811 => 'BEIGE', 812 => 'TEAL',
    ];

    public const PARTIES = [
        1001 => 'MARIA-B', 1002 => 'SAPPHIRE TEXTILES', 1003 => 'GUL AHMED', 1004 => 'Y.Z CORPORATION',
        1005 => 'ZAR TEX SAYA', 1006 => 'U&I GARMENTS', 1007 => 'AL HAMD TEXTILE',
        1008 => 'SHEIKHUPURA TEXTILE MILLS LIMITED',
    ];

    /**
     * Q_CODE => [DESCRIPTION, REED, PICK, BLEND]. DESCRIPTION is Oracle's name for the quality string, and
     * it is **nullable in practice**: 672 of the 3 035 live rows have none, and of the 149 `Q_CODE`s the
     * processing rows use only 117 resolve to one. 4106 is the fixture for that — construction columns but
     * no description — so a consumer that assumes a name is caught here.
     */
    public const QUALITIES = [
        4101 => ['88X60/30X30 100% VISCOSE [AJ]', 88, 60, 'VISCOSE'],
        4102 => ['76X68/30X30 100% COTTON [AJ]', 76, 68, 'COTTON'],
        4103 => ['110X90/40X40 100% COTTON [SL]', 110, 90, 'COTTON'],
        4104 => ['68X68/20X20 COTTON SLUB [AJ]', 68, 68, 'COTTON'],
        4105 => ['92X64/40X40 60% COTTON 40% POLYESTER [AJ]', 92, 64, 'CVC'],
        4106 => [null, 60, 60, 'COTTON'],
    ];

    public const CONTRACT_LINES = 60;

    /** The lot number two different contracts share on purpose (findings.md §5.1). */
    public const DUPLICATED_LOT_NUM = 640900;

    /** The route a lot walks through the cost centres, as (CC_CODE, TO_CC_CODE, OPERATION_CODE, MACHINE_CODE). */
    private const ROUTE = [
        [82, 101, 301, 207],
        [101, 103, 302, 210],
        [103, 104, 305, 208],
        [104, 103, 306, 210],
        [103, 108, 311, 204],
        [108, 109, 312, 201],
        [109, 110, 315, 203],
        [110, 199, 316, 203],
    ];

    public function run(): void
    {
        if (! OracleSourceConnection::isMocked()) {
            return;
        }
        if (! Schema::connection(OracleSourceConnection::NAME)->hasTable(ProcessingLot::TABLE)) {
            return; // the guarded migration did not run (real Oracle, or a connection that is not ours)
        }
        if (ProcessingLot::query()->exists()) {
            return; // idempotent
        }

        $this->masters();
        $this->transactions();
    }

    private function masters(): void
    {
        $created = CarbonImmutable::today()->subYears(3)->toDateTimeString();

        $costCentres = array_map(fn ($code) => [
            'CC_CODE' => $code, 'COST_CENTRE' => self::COST_CENTRES[$code], 'TYPE' => 'DEPARTMENT',
            'LOCATION' => 'MILL', 'CREATION_DATE' => $created, 'REMARKS' => null, 'CLOSED' => 'N',
        ], array_keys(self::COST_CENTRES));

        // Machines live in the same table, which is the whole point: there is no machine master.
        foreach (self::MACHINES as $code => [$name, $department]) {
            $costCentres[] = [
                'CC_CODE' => $code, 'COST_CENTRE' => $name, 'TYPE' => CostCentre::TYPE_MACHINE,
                'LOCATION' => 'MILL', 'CREATION_DATE' => $created,
                'REMARKS' => 'IN '.self::COST_CENTRES[$department], 'CLOSED' => null,
            ];
        }

        CostCentre::seedMock($costCentres);

        $operations = array_map(fn ($code) => [
            'CC_CODE' => self::OPERATIONS[$code][1], 'OPERATION_CODE' => $code,
            'OPERATION_NAME' => self::OPERATIONS[$code][0], 'OPERATION_TYPE' => null,
        ], array_keys(self::OPERATIONS));

        foreach (self::REUSED_OPERATIONS as [$cc, $code, $name]) {
            $operations[] = [
                'CC_CODE' => $cc, 'OPERATION_CODE' => $code, 'OPERATION_NAME' => $name,
                'OPERATION_TYPE' => null,
            ];
        }

        OracleOperation::seedMock($operations);

        OracleDesign::seedMock(array_map(
            fn ($c) => ['DESIGN_CODE' => $c, 'DESIGN_NAME' => self::DESIGNS[$c]],
            array_keys(self::DESIGNS),
        ));

        OracleColour::seedMock(array_map(
            fn ($c) => ['COLOUR_CODE' => $c, 'COLOUR_NAME' => self::COLOURS[$c]],
            array_keys(self::COLOURS),
        ));

        OracleParty::seedMock(array_map(fn ($c) => [
            'PARTY_CODE' => $c, 'PARTY_NAME' => self::PARTIES[$c], 'PARTY_TYPE' => 'CUSTOMER',
            'SHORT_NAME' => substr(self::PARTIES[$c], 0, 10), 'CITY' => 'LAHORE', 'STATUS' => 'ACTIVE',
        ], array_keys(self::PARTIES)));

        OracleQuality::seedMock(array_map(function ($c) {
            [$description, $reed, $pick, $blend] = self::QUALITIES[$c];

            return [
                'Q_CODE' => $c, 'DESCRIPTION' => $description, 'REED' => $reed, 'PICK' => $pick,
                'BLEND' => $blend, 'FAB_TYPE' => 'WOVEN', 'APPROVED' => 'Y',
            ];
        }, array_keys(self::QUALITIES)));
    }

    private function transactions(): void
    {
        $partyCodes = array_keys(self::PARTIES);
        $qualityCodes = array_keys(self::QUALITIES);
        $designCodes = array_keys(self::DESIGNS);
        $colourCodes = array_keys(self::COLOURS);
        $processTypes = ['DYED', 'PRINTED', 'HALF BLEACH', 'PFD'];
        $start = CarbonImmutable::today()->subDays(75);

        $contractHeaders = [];
        $contractLines = [];
        $contractLots = [];
        $issueHeaders = [];
        $issueLines = [];
        $processing = [];

        $pcDtlId = 50001;
        $pclDtlId = 55001;
        $giMainId = 60001;
        $giDtlId = 70001;
        $processingLotId = 80001;

        for ($i = 0; $i < self::CONTRACT_LINES; $i++) {
            $contractNum = 260001 + intdiv($i, 4);
            $pcMainId = 40000 + intdiv($i, 4);
            // The deliberate duplicate: two contracts carry the same lot number (findings.md §5.1).
            $lotNum = ($i === 17 || $i === 43) ? self::DUPLICATED_LOT_NUM : 640101 + $i;
            $party = $partyCodes[$i % count($partyCodes)];
            $quality = $qualityCodes[$i % count($qualityCodes)];
            $qnty = 800 + ($i % 6) * 250;
            $dated = $start->addDays($i % 40);
            $gsm = 120 + ($i % 5) * 10;

            // One contract header per four detail lines. CONTRACT_NUM, DATED, PARTY_CODE and REF_NUM live
            // here, not on the line.
            if ($i % 4 === 0) {
                $contractHeaders[] = [
                    'PC_MAIN_ID' => $pcMainId, 'CONTRACT_NUM' => $contractNum,
                    'DATED' => $dated->toDateTimeString(), 'PARTY_CODE' => $party, 'WITH_INV' => 'Y',
                    'REMARKS' => null, 'APPROVED' => 'Y', 'APPROVED_BY' => 'PPCUSER',
                    'APPROVED_DATE' => $dated->addDay()->toDateTimeString(),
                    'ENTRY_DATE' => $dated->toDateTimeString(), 'USER_ID' => 'PPCUSER',
                    'COMPUTER' => 'PPC-OFFICE-01', 'CHECKED' => 'Y', 'CLOSED' => 'N',
                    'REF_NUM' => 'REF-'.(2600 + $i), 'NATURE' => 'LOCAL', 'CATEGORY' => 'PROCESSING',
                    'PACKING_TYPE' => 'ROLL',
                ];
            }

            $contractLines[] = [
                'PC_MAIN_ID' => $pcMainId, 'PC_DTL_ID' => $pcDtlId, 'Q_CODE' => $quality,
                'WIDTH_UOM' => 'IN', 'G_WIDTH' => 60 + ($i % 3), 'F_WIDTH' => 56 + ($i % 3),
                'PROCESS_TYPE' => $processTypes[$i % count($processTypes)],
                'DESIGN_CODE' => $designCodes[$i % count($designCodes)], 'VERSION_CODE' => 1,
                'COLOUR_CODE' => $colourCodes[$i % count($colourCodes)],
                'QNTY' => $qnty, 'FINISH_TYPE' => 'SOFT', 'RATE' => 42.50,
                // Denormalised and usually empty: only 8 212 of 45 561 live lines have it, and for the
                // lines the processing rows point at it is null on 3 453 of 3 557. PC_DTL_LOTS below is the
                // real lot↔line link, so this is populated on about a fifth of the fixtures — and on one of
                // the two lines that share lot 640900, so both paths are exercised.
                'LOT_NUM' => ($i % 5 === 0 || $i === 17) ? $lotNum : null,
                'CLOSED' => 'N', 'LAB_TEST' => 'N',
            ];

            // GSM lives here and nowhere else, and this table fans out: several rows per (line, lot), with
            // GSM disagreeing between them. A reader that joined would multiply the line's quantity.
            for ($r = 0; $r <= $i % 3; $r++) {
                $contractLots[] = [
                    'PC_MAIN_ID' => $pcMainId, 'PC_DTL_ID' => $pcDtlId, 'PCL_DTL_ID' => $pclDtlId++,
                    'Q_CODE' => $quality, 'G_WIDTH' => 60 + ($i % 3),
                    'QNTY' => intdiv($qnty, 1 + $i % 3), 'LOT_NUM' => $lotNum, 'GSM' => $gsm + $r * 5,
                ];
            }

            // One issue document per four lines, i.e. one per contract.
            if ($i % 4 === 0) {
                $issueHeaders[] = [
                    'GI_MAIN_ID' => $giMainId, 'GI_NUM' => 9000 + intdiv($i, 4),
                    'DATED' => $dated->addDay()->toDateTimeString(), 'REF_NUM' => 'GI'.(1000 + intdiv($i, 4)),
                    'SECTION_CODE' => 'PPC', 'REMARKS' => null, 'FINAL' => 'Y',
                    'FINAL_DATE' => $dated->addDays(2)->toDateTimeString(), 'FINAL_BY' => 'PPCUSER',
                    'ENTRY_DATE' => $dated->addDay()->toDateTimeString(), 'USER_ID' => 'PPCUSER',
                    'COMPUTER' => 'PPC-OFFICE-01', 'CC_CODE' => 82,
                ];
                $giMainId++;
            }

            $issueLines[] = [
                'GI_MAIN_ID' => $giMainId - 1, 'GI_DTL_ID' => $giDtlId, 'LOT_NUM' => $lotNum,
                'PC_MAIN_ID' => $pcMainId, 'PC_DTL_ID' => $pcDtlId, 'CONTRACT_NUM' => $contractNum,
                'PARTY_CODE' => $party, 'Q_CODE' => $quality, 'G_WIDTH' => 60 + ($i % 3), 'QNTY' => $qnty,
                'RATE' => 42.50, 'THAN' => 10 + ($i % 8), 'LOCATION' => 'STORE-A', 'GSM' => $gsm,
            ];

            // How far down the route this lot got: 0 (not started) to the whole route.
            $through = $i % (count(self::ROUTE) + 1);
            $qtyOnFloor = $qnty;

            for ($s = 0; $s < $through; $s++) {
                [$cc, $toCc, $operation, $machine] = self::ROUTE[$s];
                $used = $qtyOnFloor;
                // A 2% shrinkage at the two wet stages, whole metres like everything else in Oracle.
                $produced = in_array($operation, [306, 310], true) ? (int) floor($used * 0.98) : $used;
                $runStart = $dated->addDays(3 + $s * 2)->setTime(8 + ($s % 10), 0);

                $processing[] = [
                    'PROCESSING_LOT_ID' => $processingLotId++, 'CC_CODE' => $cc, 'MACHINE_CODE' => $machine,
                    'REF_NUM' => 'REF-'.(2600 + $i), 'LOT_NUM' => $lotNum,
                    // The contract line, straight off the row: PC_DTL_ID is a real column here.
                    'PC_DTL_ID' => $pcDtlId, 'CONTRACT_NUM' => $contractNum, 'PARTY_CODE' => $party,
                    'DATED' => $runStart->toDateTimeString(), 'Q_CODE' => $quality,
                    'G_WIDTH' => 60 + ($i % 3), 'F_WIDTH' => 56 + ($i % 3),
                    'PROCESS_TYPE' => $processTypes[$i % count($processTypes)],
                    'DESIGN_CODE' => $designCodes[$i % count($designCodes)], 'VERSION_CODE' => 1,
                    'COLOUR_CODE' => $colourCodes[$i % count($colourCodes)],
                    'QNTY_USED' => $used, 'QNTY_PRODUCED' => $produced, 'REMARKS' => null,
                    'TO_CC_CODE' => $toCc,
                    // Operation is left unrecorded on one stage in twelve — Oracle allows it (findings.md §2.3).
                    'OPERATION_CODE' => ($i + $s) % 12 === 7 ? null : $operation,
                    'ENTRY_DATE' => $runStart->addHours(6)->toDateTimeString(), 'USER_ID' => 'OP'.(10 + $s),
                    'COMPUTER' => 'FLOOR-PC-'.(1 + $s % 4),
                    'LOT_STARTTIME' => $runStart->toDateTimeString(),
                    'LOT_ENDTIME' => $runStart->addHours(3 + $s % 4)->toDateTimeString(),
                    'F_WIDTH_PRODUCED' => 55 + ($i % 3),
                    'REPROCESS' => ($i % 11 === 4 && $s === 2) ? 'Y' : 'N', 'NON_PROCESS' => 'N',
                    'GREIGE_RECEIPT' => $s === 0 ? 'Y' : 'N',
                    'GREIGE_RECEIPT_BY' => $s === 0 ? 'STOREKEEPER' : null,
                    'GREIGE_RECEIPT_DT' => $s === 0 ? $runStart->toDateTimeString() : null,
                ];

                $qtyOnFloor = $produced;
            }

            $pcDtlId++;
            $giDtlId++;
        }

        ProcessingContractHeader::seedMock($contractHeaders);
        ProcessingContractLine::seedMock($contractLines);
        ProcessingContractLot::seedMock($contractLots);
        GreigeIssueHeader::seedMock($issueHeaders);
        GreigeIssueLine::seedMock($issueLines);
        ProcessingLot::seedMock($processing);
    }
}
