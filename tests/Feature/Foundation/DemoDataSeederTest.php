<?php

use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Models\UserDevice;
use App\Modules\Foundation\Access\Approval;
use App\Modules\Foundation\Models\Machine;
use App\Modules\OracleSync\Source\Models\ProcessingLot;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\MachineAllocation;
use App\Modules\Production\Services\LedgerService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccessSeeder;
use Database\Seeders\DemoProductionSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('seeds signups in every approval state', function () {
    $signups = User::query()->where('email', 'like', '%@'.DemoAccessSeeder::DOMAIN)->get();

    expect($signups)->toHaveCount(count(DemoAccessSeeder::SIGNUPS))
        ->and($signups->where('approval_status', Approval::PENDING))->toHaveCount(4)
        ->and($signups->where('approval_status', Approval::APPROVED))->toHaveCount(2)
        ->and($signups->where('approval_status', Approval::REJECTED))->toHaveCount(2)
        ->and($signups->firstWhere('approval_status', Approval::REJECTED)->rejected_reason)->not->toBeNull()
        ->and($signups->where('email_verified_at', null))->toHaveCount(1);
});

it('seeds devices, one of them unnamed, and an action log', function () {
    expect(UserDevice::query()->count())->toBe(count(DemoAccessSeeder::DEVICES))
        ->and(UserDevice::query()->whereNull('computer_name')->count())->toBe(1)
        ->and(UserAction::query()->count())->toBeGreaterThan(50)
        ->and(UserAction::query()->whereNotNull('computer_name')->exists())->toBeTrue();
});

it('seeds contracts and lots whose ledger balances hold', function () {
    $lots = Lot::query()->get();

    expect(Contract::query()->count())->toBe(DemoProductionSeeder::CONTRACTS)
        ->and($lots)->toHaveCount(DemoProductionSeeder::CONTRACTS * DemoProductionSeeder::LOTS_PER_CONTRACT);

    $ledger = app(LedgerService::class);
    foreach ($lots as $lot) {
        $ledger->assertInvariant($lot);
    }
});

it('seeds lots in a variety of positions, so every screen has something', function () {
    expect(Lot::query()->where('greige_in_stock_oracle', '>', 0)->exists())->toBeTrue()
        ->and(Lot::query()->where('wip', '>', 0)->exists())->toBeTrue()
        ->and(Lot::query()->where('dispatch', '>', 0)->exists())->toBeTrue()
        ->and(Lot::query()->where('loss_qty', '>', 0)->exists())->toBeTrue()
        ->and(Lot::query()->where('state', 'hold')->exists())->toBeTrue()
        ->and(Lot::query()->distinct()->count('po_type_oracle'))->toBeGreaterThan(1);
});

it('seeds machine capacity and a month of allocations', function () {
    expect(Machine::query()->whereNotNull('daily_capacity')->count())->toBeGreaterThan(0)
        ->and(Machine::query()->whereNotNull('hourly_capacity')->count())->toBeGreaterThan(0)
        ->and(MachineAllocation::query()->count())->toBeGreaterThan(200)
        ->and(MachineAllocation::query()->distinct()->count('allocated_on'))->toBeGreaterThan(20);
});

it('seeds the mocked Oracle source, including a lot number that recurs across contracts', function () {
    expect(ProcessingLot::query()->count())->toBeGreaterThan(100)
        ->and(ProcessingLot::query()->where('LOT_NUM', 640900)->pluck('CONTRACT_NUM')->unique())->toHaveCount(2);
});

it('is idempotent: re-running changes nothing', function () {
    $before = [
        'users' => User::query()->count(),
        'lots' => Lot::query()->count(),
        'movements' => LotMovement::query()->count(),
        'actions' => UserAction::query()->count(),
        'allocations' => MachineAllocation::query()->count(),
        'oracle' => ProcessingLot::query()->count(),
    ];

    $this->seed(DatabaseSeeder::class);

    expect([
        'users' => User::query()->count(),
        'lots' => Lot::query()->count(),
        'movements' => LotMovement::query()->count(),
        'actions' => UserAction::query()->count(),
        'allocations' => MachineAllocation::query()->count(),
        'oracle' => ProcessingLot::query()->count(),
    ])->toBe($before);
});
