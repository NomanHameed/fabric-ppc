<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Activity\Services\ActionLogger;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\Quality;
use App\Modules\Foundation\Support\PartyName;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\ColourOverride;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Services\IssueService;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotIntakeService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Services\LotStateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Contracts, lots and a plausible production history, so every Production screen and the lot timeline have
 * something real to show.
 *
 * Everything that moves metres goes through LedgerService (via the intake and issue services), never through
 * a direct insert: the demo data obeys the same invariants as production data, and `lot_balances` and the
 * derived lot totals are correct by construction.
 *
 * Fully deterministic: no randomness, every figure derived from the lot's index.
 */
class DemoProductionSeeder extends Seeder
{
    /** First PO number; also the idempotency marker. */
    public const FIRST_PO = 270001;

    public const CONTRACTS = 8;

    public const LOTS_PER_CONTRACT = 3;

    /** Quality descriptions, parsed into the quality master by Quality::fromOracle. */
    private const QUALITIES = [
        [4101, '88X60/30X30 100% VISCOSE [AJ]'],
        [4102, '76X68/30X30 100% COTTON [AJ]'],
        [4103, '110X90/40X40 100% COTTON [SL]'],
        [4104, '68X68/20X20 COTTON SLUB [AJ]'],
        [4105, '92X64/40X40 60% COTTON 40% POLYESTER [AJ]'],
        [4106, '60X60/20X20 100% COTTON DOBBY [SL]'],
    ];

    private const PO_TYPES = ['DYED', 'PRINTED', 'HALF BLEACH'];

    private const COLOURS = [
        ['C-101', 'NAVY BLUE'], ['C-204', 'IVORY'], ['C-330', 'ROSE PINK'],
        ['C-415', 'FOREST GREEN'], ['C-512', 'CHARCOAL'], ['C-620', 'MUSTARD'],
    ];

    private const COLOUR_STATUSES = ['APPROVED', 'APPROVED', 'MWS_APP', 'DIP', 'SHADE', 'DIRECT_PROD'];

    public function run(): void
    {
        if (Contract::query()->where('po_no', self::FIRST_PO)->exists()) {
            return; // idempotent: the ledger is insert-only, so this must not run twice
        }

        $ppc = $this->user(Permissions::ROLE_PPC);
        $manager = $this->user(Permissions::ROLE_MANAGER);
        $lab = $this->user(Permissions::ROLE_COLOUR_LAB);

        $qualities = array_map(fn (array $q) => Quality::fromOracle($q[0], $q[1]), self::QUALITIES);
        $parties = $this->parties();

        $intake = app(LotIntakeService::class);
        $routes = app(LotRouteService::class);
        $issues = app(IssueService::class);
        $ledger = app(LedgerService::class);
        $states = app(LotStateService::class);
        $logger = app(ActionLogger::class);

        $lotNo = 640100;
        $oracleLineId = 900100;

        for ($c = 0; $c < self::CONTRACTS; $c++) {
            $poDate = CarbonImmutable::now()->subDays(60 - $c * 5)->startOfDay();
            $party = $parties[$c % count($parties)];
            $contract = Contract::query()->create([
                'po_no' => self::FIRST_PO + $c,
                'party_id' => $party->id,
                'ref_no' => 'REF-'.(2600 + $c),
                'po_date' => $poDate,
                'contract_qty' => 0,
            ]);
            $contractQty = 0.0;

            for ($l = 0; $l < self::LOTS_PER_CONTRACT; $l++) {
                $n = $c * self::LOTS_PER_CONTRACT + $l;          // 0 … 23, drives every variation
                $greige = 800 + ($n % 6) * 250;                  // 800 … 2050 metres
                [$colourCode, $colour] = self::COLOURS[$n % count(self::COLOURS)];

                $lot = Lot::query()->create([
                    'oracle_line_id' => $oracleLineId++,
                    'lot_no_oracle' => (string) $lotNo++,
                    'contract_id' => $contract->id,
                    'quality_id' => $qualities[$n % count($qualities)]->id,
                    'gsm' => 120 + ($n % 5) * 10,
                    'design_oracle_id' => 'D-'.(700 + $n),
                    'design' => 'Design '.(700 + $n),
                    'color_oracle_id' => $colourCode,
                    'color_oracle_name' => $colour,
                    'gw_oracle' => 60 + ($n % 3),
                    'f_width' => 56 + ($n % 3),
                    'po_type_oracle' => self::PO_TYPES[$n % count(self::PO_TYPES)],
                    'greige_received_oracle' => $greige,
                    'clr_status_oracle' => self::COLOUR_STATUSES[$n % count(self::COLOUR_STATUSES)],
                    // The denormalised copies the report prints without joining (Lot::ORACLE_FIELDS).
                    'party_oracle_id' => $party->oracle_code,
                    'party_oracle_name' => $party->name,
                    'po_no' => $contract->po_no,
                    'ref' => $contract->ref_no,
                    'date_oracle' => $poDate,
                    'quality' => $qualities[$n % count($qualities)]->description,
                    'created_at' => $poDate->addDay(),
                    'updated_at' => $poDate->addDay(),
                ]);
                $contractQty += $greige;

                $intake->receive($lot, $ppc->id);   // import movement + route from the matching template
                $this->runProduction($lot, $n, $poDate, $ppc, $manager, $lab, $issues, $ledger, $states, $logger);
                $routes->refreshStatuses($lot);
            }

            $contract->forceFill(['contract_qty' => $contractQty])->save();
        }
    }

    /**
     * Push one lot as far along its route as its index says, so the demo contains lots in every state:
     * untouched, partly through, finished, dispatched, on hold, with a loss, and with a colour override.
     */
    private function runProduction(
        Lot $lot,
        int $n,
        CarbonImmutable $poDate,
        User $ppc,
        User $manager,
        User $lab,
        IssueService $issues,
        LedgerService $ledger,
        LotStateService $states,
        ActionLogger $logger,
    ): void {
        $steps = $lot->routeSteps()->get();
        if ($steps->isEmpty()) {
            return;
        }

        $stage = $n % 6;   // 0 in stock only, 1 issued, 2–3 part way, 4 finished, 5 finished + dispatched
        if ($stage === 0) {
            return;
        }

        $issuedAt = $poDate->addDays(2);
        $qty = round((float) $lot->greige_received_oracle, 2);
        // IssueService records `lot.issued` itself now, stamped with the issue date, so there is nothing to
        // log here — doing it again would put two identical rows on every demo lot's timeline.
        $issues->issue($lot, $qty, $ppc, $issuedAt);

        if ($stage === 1) {
            return;
        }

        $through = match ($stage) {
            2 => 1,
            3 => (int) ceil($steps->count() / 2),
            default => $steps->count(),
        };

        $from = Position::issued();
        $movedAt = $issuedAt;

        for ($i = 0; $i < $through; $i++) {
            $movedAt = $movedAt->addDays(2)->setTime(10 + $i % 8, 30);
            $to = Position::step($steps[$i]->id);
            $ledger->post($lot, 'process', $from, $to, $qty, $qty, ['userId' => $ppc->id, 'movedAt' => $movedAt]);
            $logger->record(ActionKeys::LOT_MOVED, $lot, [
                'lot' => $lot->lot_no_oracle,
                'from' => $i === 0 ? 'ISSUED' : $this->stepName($steps[$i - 1]),
                'to' => $this->stepName($steps[$i]),
            ], $ppc, null, null, null, $movedAt);
            $from = $to;
        }

        // A loss on every fourth lot: shrinkage, the mill's most common one.
        if ($n % 4 === 3) {
            $loss = round($qty * 0.02, 2);
            $movedAt = $movedAt->addHours(3);
            $ledger->post($lot, 'loss', $from, Position::loss(), $loss, $loss, [
                'userId' => $ppc->id, 'movedAt' => $movedAt, 'reason' => 'SHRINKAGE',
            ]);
            $qty = round($qty - $loss, 2);
        }

        // A colour override wherever production ran on a lot whose colour is not approved.
        if (in_array($lot->clr_status_oracle, ['DIP', 'SHADE'], true)) {
            ColourOverride::query()->create([
                'lot_id' => $lot->id,
                'reason' => 'Party confirmed the shade on the phone; written approval to follow.',
                'approved_by' => $manager->id,
                'approved_at' => $issuedAt->addDay(),
            ]);
        } else {
            $logger->record(ActionKeys::COLOUR_APPROVED, $lot, ['colour' => (string) $lot->color_oracle_name, 'lot' => $lot->lot_no_oracle],
                $lab, null, null, null, $issuedAt->addDay());
        }

        if ($stage < 4) {
            // Every ninth lot waits on the party rather than on the floor.
            if ($n % 9 === 5) {
                // Through asAuditUser so the state change lands in `audits` with the manager on it: that row
                // is what the lot timeline reads for "put on hold".
                // LotStateService records `lot.held` itself now (at the time of the run, not $movedAt: the
                // transition takes no backdate), so this seeder no longer logs it a second time.
                ImportCommitService::asAuditUser($manager, fn () => $states->hold($lot, 'PARTY_HOLD', $manager));
            }

            return;
        }

        $movedAt = $movedAt->addDays(2);
        $ledger->post($lot, 'process', $from, Position::finished(), $qty, $qty, ['userId' => $ppc->id, 'movedAt' => $movedAt]);

        if ($stage === 5) {
            $movedAt = $movedAt->addDays(3);
            $ledger->post($lot, 'dispatch', Position::finished(), Position::dispatched(), $qty, $qty, ['userId' => $ppc->id, 'movedAt' => $movedAt]);
            $logger->record(ActionKeys::LOT_DISPATCHED, $lot, ['qty' => $qty, 'lot' => $lot->lot_no_oracle], $ppc, null, null, null, $movedAt);
        }
    }

    private function stepName(LotRouteStep $step): string
    {
        $operation = $step->operation;

        return $operation === null ? 'STEP' : $operation->name;
    }

    /** @return list<Party> */
    private function parties(): array
    {
        $parties = array_values(Party::query()->where('is_active', true)->orderBy('id')->limit(self::CONTRACTS)->get()->all());

        // Standalone runs (no PartySeeder) still need someone to hang the contracts on.
        for ($i = count($parties); $i < self::CONTRACTS; $i++) {
            $parties[] = Party::query()->create([
                'name' => 'Demo Party '.($i + 1),
                'name_normalized' => PartyName::normalize('Demo Party '.($i + 1)),
                'type' => $i % 2 === 0 ? 'brand' : 'local',
                'oracle_code' => 8000 + $i,
            ]);
        }

        return $parties;
    }

    private function user(string $role): User
    {
        return User::query()->where('email', DemoUsersSeeder::emailFor($role))->first()
            ?? User::query()->orderBy('id')->firstOrFail();
    }
}
