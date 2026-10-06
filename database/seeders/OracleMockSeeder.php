<?php

namespace Database\Seeders;

use App\Modules\OracleSync\Source\Models\CostCentre;
use App\Modules\OracleSync\Source\Models\GreigeIssueHeader;
use App\Modules\OracleSync\Source\Models\GreigeIssueLine;
use App\Modules\OracleSync\Source\Models\OracleColour;
use App\Modules\OracleSync\Source\Models\OracleDesign;
use App\Modules\OracleSync\Source\Models\OracleMachine;
use App\Modules\OracleSync\Source\Models\OracleOperation;
use App\Modules\OracleSync\Source\Models\OracleParty;
use App\Modules\OracleSync\Source\Models\OracleQuality;
use App\Modules\OracleSync\Source\Models\ProcessingContractLine;
use App\Modules\OracleSync\Source\Models\ProcessingLot;
use App\Modules\OracleSync\Source\OracleSourceConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Rows for the mocked Oracle source. Does nothing at all unless `oracle_src` is a local stand-in — the real
 * instance is read-only to this application, and SourceModel::seedMock() refuses anyway.
 *
 * The data follows findings.md: quantities are whole metres, CC_CODE → TO_CC_CODE is the movement, and
 * LOT_NUM is NOT unique — lot 640900 appears under two different contracts on purpose (§5.1), so the fan-out
 * defect in `processing_lots_trans` can be demonstrated and the corrected reader proved against it.
 */
class OracleMockSeeder extends Seeder
{
    /** CC_CODE => [name, short] — the cost centres the mill's departments map onto. */
    public const COST_CENTRES = [
        82 => ['GREIGE STORE', 'GREIGE'],
        101 => ['SINGEING', 'SING'],
        102 => ['KIER BLEACHING', 'KIER'],
        103 => ['JIGGER DYEING', 'JIGGER'],
        104 => ['MERCERIZING', 'MERC'],
        105 => ['BATCHING - STENTER', 'BATCH'],
        106 => ['PRINTING', 'PRINT'],
        107 => ['CURING', 'CURE'],
        108 => ['DRYING', 'DRY'],
        109 => ['FINISH - STENTER', 'FINISH'],
        110 => ['SANFORIZING / CALANDER', 'SANFO'],
        111 => ['ROLLING', 'ROLL'],
        112 => ['STITCHING', 'STITCH'],
        199 => ['FINISHED GOODS STORE', 'FG'],
    ];

    public const MACHINES = [
        201 => ['STENTER NO. 1', 109], 202 => ['STENTER NO. 2', 109], 203 => ['SANFORIZER', 110],
        204 => ['DRYING #01', 108], 205 => ['DRYING #02', 108], 206 => ['JUMBO', 108],
        207 => ['SINGEING RANGE', 101], 208 => ['MERCERIZER', 104], 209 => ['DIGITAL PRINTER', 106],
        210 => ['JIGGER DYEING', 103],
    ];

    public const OPERATIONS = [
        301 => ['SINGING', 101], 302 => ['SCOUR+BLEACH', 103], 303 => ['DESIZE+BLEACH', 103],
        304 => ['SINGLE BLEACH', 103], 305 => ['MERCERIZING', 104], 306 => ['REACTIVE DYE', 103],
        307 => ['PIGMENT PADDING', 105], 308 => ['REACTIVE PRINTING', 106], 309 => ['REACTIVE CURE', 107],
        310 => ['WASHING', 103], 311 => ['DRY', 108], 312 => ['DYE FINISH FOR SANFORIZING', 109],
        313 => ['PRINT FINISH', 109], 314 => ['WHITE FINISH FOR SANFORIZING', 109], 315 => ['SANFORIZE', 110],
        316 => ['ROLLING', 111], 317 => ['STITCHING', 112],
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

    public const QUALITIES = [
        4101 => '88X60/30X30 100% VISCOSE [AJ]',
        4102 => '76X68/30X30 100% COTTON [AJ]',
        4103 => '110X90/40X40 100% COTTON [SL]',
        4104 => '68X68/20X20 COTTON SLUB [AJ]',
        4105 => '92X64/40X40 60% COTTON 40% POLYESTER [AJ]',
        4106 => '60X60/20X20 100% COTTON DOBBY [SL]',
    ];

    public const CONTRACT_LINES = 60;

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
        if (! Schema::connection(OracleSourceConnection::NAME)->hasTable('PROCESSING_LOTS')) {
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
        CostCentre::seedMock(array_map(
            fn ($code) => ['CC_CODE' => $code, 'CC_NAME' => self::COST_CENTRES[$code][0], 'CC_SHORT' => self::COST_CENTRES[$code][1], 'IS_ACTIVE' => 'Y'],
            array_keys(self::COST_CENTRES),
        ));

        OracleMachine::seedMock(array_map(
            fn ($code) => ['MACHINE_CODE' => $code, 'MACHINE_NAME' => self::MACHINES[$code][0], 'CC_CODE' => self::MACHINES[$code][1], 'IS_ACTIVE' => 'Y'],
            array_keys(self::MACHINES),
        ));

        OracleOperation::seedMock(array_map(
            fn ($code) => ['OPERATION_CODE' => $code, 'OPERATION_NAME' => self::OPERATIONS[$code][0], 'CC_CODE' => self::OPERATIONS[$code][1], 'IS_ACTIVE' => 'Y'],
            array_keys(self::OPERATIONS),
        ));

        OracleDesign::seedMock(array_map(fn ($c) => ['DESIGN_CODE' => $c, 'DESIGN_NAME' => self::DESIGNS[$c]], array_keys(self::DESIGNS)));
        OracleColour::seedMock(array_map(fn ($c) => ['COLOUR_CODE' => $c, 'COLOUR_NAME' => self::COLOURS[$c]], array_keys(self::COLOURS)));
        OracleParty::seedMock(array_map(fn ($c) => ['PARTY_CODE' => $c, 'PARTY_NAME' => self::PARTIES[$c]], array_keys(self::PARTIES)));
        OracleQuality::seedMock(array_map(fn ($c) => ['Q_CODE' => $c, 'QUALITY_NAME' => self::QUALITIES[$c]], array_keys(self::QUALITIES)));
    }

    private function transactions(): void
    {
        $partyCodes = array_keys(self::PARTIES);
        $qualityCodes = array_keys(self::QUALITIES);
        $designCodes = array_keys(self::DESIGNS);
        $colourCodes = array_keys(self::COLOURS);
        $processTypes = ['DYED', 'PRINTED', 'HALF BLEACH', 'PFD'];
        $start = CarbonImmutable::today()->subDays(75);

        $contractLines = [];
        $issueHeaders = [];
        $issueLines = [];
        $processing = [];

        $pcDtlId = 50001;
        $giMainId = 60001;
        $giDtlId = 70001;
        $processingLotId = 80001;

        for ($i = 0; $i < self::CONTRACT_LINES; $i++) {
            $contractNum = 260001 + intdiv($i, 4);
            // The deliberate duplicate: two contracts carry the same lot number (findings.md §5.1).
            $lotNum = $i === 17 ? 640900 : ($i === 43 ? 640900 : 640101 + $i);
            $party = $partyCodes[$i % count($partyCodes)];
            $quality = $qualityCodes[$i % count($qualityCodes)];
            $qnty = 800 + ($i % 6) * 250;
            $dated = $start->addDays($i % 40);

            $contractLines[] = [
                'PC_DTL_ID' => $pcDtlId, 'PC_MAIN_ID' => 40000 + intdiv($i, 4), 'CONTRACT_NUM' => $contractNum,
                'PARTY_CODE' => $party, 'Q_CODE' => $quality, 'LOT_NUM' => $lotNum, 'REF_NUM' => 'REF-'.(2600 + $i),
                'DATED' => $dated->toDateTimeString(), 'GSM' => 120 + ($i % 5) * 10,
                'DESIGN_CODE' => $designCodes[$i % count($designCodes)],
                'VERSION_CODE' => 1, 'COLOUR_CODE' => $colourCodes[$i % count($colourCodes)],
                'G_WIDTH' => 60 + ($i % 3), 'F_WIDTH' => 56 + ($i % 3),
                'PROCESS_TYPE' => $processTypes[$i % count($processTypes)],
                'QNTY' => $qnty, 'QNTY_CONT' => $qnty * 4,
            ];

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
                'PC_MAIN_ID' => 40000 + intdiv($i, 4), 'PC_DTL_ID' => $pcDtlId, 'CONTRACT_NUM' => $contractNum,
                'PARTY_CODE' => $party, 'Q_CODE' => $quality, 'G_WIDTH' => 60 + ($i % 3), 'QNTY' => $qnty,
                'RATE' => 42.50, 'THAN' => 10 + ($i % 8), 'LOCATION' => 'STORE-A', 'GSM' => 120 + ($i % 5) * 10,
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
                    'PROCESSING_LOT_ID' => $processingLotId++, 'LOT_NUM' => $lotNum,
                    'PLOT_NUM' => 'B'.$lotNum.'-'.($s + 1), 'CONTRACT_NUM' => $contractNum, 'PARTY_CODE' => $party,
                    'Q_CODE' => $quality, 'REF_NUM' => 'REF-'.(2600 + $i), 'DATED' => $runStart->toDateTimeString(),
                    'CC_CODE' => $cc, 'TO_CC_CODE' => $toCc, 'MACHINE_CODE' => $machine,
                    // Operation is left unrecorded on one stage in twelve — Oracle allows it (findings.md §2.3).
                    'OPERATION_CODE' => ($i + $s) % 12 === 7 ? null : $operation,
                    'QNTY_USED' => $used, 'QNTY_PRODUCED' => $produced,
                    'G_WIDTH' => 60 + ($i % 3), 'F_WIDTH' => 56 + ($i % 3), 'F_WIDTH_PRODUCED' => 55 + ($i % 3),
                    'PROCESS_TYPE' => $processTypes[$i % count($processTypes)],
                    'DESIGN_CODE' => $designCodes[$i % count($designCodes)], 'VERSION_CODE' => 1,
                    'COLOUR_CODE' => $colourCodes[$i % count($colourCodes)],
                    'LOT_STARTTIME' => $runStart->toDateTimeString(),
                    'LOT_ENDTIME' => $runStart->addHours(3 + $s % 4)->toDateTimeString(),
                    'REPROCESS' => ($i % 11 === 4 && $s === 2) ? 'Y' : 'N', 'NON_PROCESS' => 'N',
                    'GREIGE_RECEIPT' => $s === 0 ? 'Y' : 'N',
                    'GREIGE_RECEIPT_BY' => $s === 0 ? 'STOREKEEPER' : null,
                    'GREIGE_RECEIPT_DT' => $s === 0 ? $runStart->toDateTimeString() : null,
                    'ENTRY_DATE' => $runStart->addHours(6)->toDateTimeString(), 'USER_ID' => 'OP'.(10 + $s),
                    'COMPUTER' => 'FLOOR-PC-'.(1 + $s % 4), 'REMARKS' => null,
                ];

                $qtyOnFloor = $produced;
            }

            $pcDtlId++;
            $giDtlId++;
        }

        ProcessingContractLine::seedMock($contractLines);
        GreigeIssueHeader::seedMock($issueHeaders);
        GreigeIssueLine::seedMock($issueLines);
        ProcessingLot::seedMock($processing);
    }
}
