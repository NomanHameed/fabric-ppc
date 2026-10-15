<?php

use App\Models\User;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\IssueService;
use App\Modules\Production\Services\LedgerService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->ledger = app(LedgerService::class);
    $this->issues = app(IssueService::class);
    $this->lot = Lot::factory()->create(['greige_received_oracle' => 500, 'design' => 'E1143/119', 'color_oracle_name' => '520 PLUM']);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 500);
});

function receivedLot(LedgerService $ledger, array $attrs, float $qty): Lot
{
    $lot = Lot::factory()->create($attrs + ['greige_received_oracle' => $qty]);
    if ($qty > 0) {
        $ledger->post($lot, 'import', null, Position::stock(), 0, $qty);
    }

    return $lot;
}

// ---- service ------------------------------------------------------------------------------------------------

it('posts STOCK → ISSUED through the ledger, partial quantities allowed', function () {
    $on = now()->subDays(2)->startOfDay();
    $m = $this->issues->issue($this->lot, 120.5, $this->ppc, $on, 'Jigger 2');

    expect($m->kind)->toBe('issue')->and($m->from_position)->toBe('STOCK')->and($m->to_position)->toBe('ISSUED')
        ->and((float) $m->qty_out_of_from)->toBe(120.5)->and((float) $m->qty_into_to)->toBe(120.5)
        ->and($m->user_id)->toBe($this->ppc->id)->and($m->reason)->toBe('Jigger 2')
        ->and($m->moved_at->equalTo($on))->toBeTrue();

    $this->issues->issue($this->lot, 379.5, $this->ppc);
    $lot = $this->lot->fresh();
    expect((float) $lot->greige_in_stock_oracle)->toBe(0.0)->and((float) $lot->qty_issued)->toBe(500.0)->and((float) $lot->wip)->toBe(500.0)
        ->and($this->ledger->balances($lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 500.0]);
    $this->ledger->assertInvariant($lot);
});

it('issues the whole stock of every open lot on the PO and skips held, cancelled, empty and other POs', function () {
    $contract = $this->lot->contract;
    $partly = receivedLot($this->ledger, ['contract_id' => $contract->id], 300);
    $this->issues->issue($partly, 100, $this->ppc);
    $held = receivedLot($this->ledger, ['contract_id' => $contract->id, 'state' => 'hold'], 10);
    $cancelled = receivedLot($this->ledger, ['contract_id' => $contract->id, 'state' => 'cancelled'], 10);
    $empty = receivedLot($this->ledger, ['contract_id' => $contract->id], 0);
    $other = receivedLot($this->ledger, [], 40);

    $on = now()->subDay()->startOfDay();
    expect($this->issues->issueContract($contract, $this->ppc, $on))->toBe(2);

    expect((float) $this->lot->fresh()->greige_in_stock_oracle)->toBe(0.0)->and((float) $this->lot->fresh()->qty_issued)->toBe(500.0)
        ->and((float) $partly->fresh()->greige_in_stock_oracle)->toBe(0.0)->and((float) $partly->fresh()->qty_issued)->toBe(300.0)
        ->and((float) $held->fresh()->greige_in_stock_oracle)->toBe(10.0)->and((float) $cancelled->fresh()->greige_in_stock_oracle)->toBe(10.0)
        ->and($empty->movements()->count())->toBe(0)->and((float) $other->fresh()->greige_in_stock_oracle)->toBe(40.0)
        ->and($this->lot->movements()->where('kind', 'issue')->firstOrFail()->moved_at->format('Y-m-d H:i:s'))->toBe($on->format('Y-m-d H:i:s'));

    expect($this->issues->issueContract($contract, $this->ppc))->toBe(0);
});

// ---- screen -------------------------------------------------------------------------------------------------

it('renders the page with the recent issues, newest first', function () {
    $this->issues->issue($this->lot, 50, $this->ppc, now()->startOfDay(), 'first');
    $this->issues->issue($this->lot, 25, $this->ppc, now()->startOfDay(), 'second');

    $this->actingAs($this->ppc)->get('/production/issue')->assertOk()
        ->assertInertia(fn ($page) => $page->component('production/IssueToFloor')
            ->has('recent', 2)
            ->where('recent.0.remarks', 'second')->where('recent.0.qty', 25)->where('recent.0.lot_no_oracle', $this->lot->lot_no_oracle)
            ->where('recent.0.po_no', $this->lot->contract->po_no)->where('recent.0.design', 'E1143/119')->where('recent.0.color_oracle_name', '520 PLUM')
            ->where('recent.0.greige_in_stock_oracle', 425)->where('recent.0.reversal', false)->where('recent.0.user', $this->ppc->name)
            ->where('recent.0.moved_at', now()->format('d-m-Y'))
            ->where('recent.1.remarks', 'first'));
});

it('issues part of a lot with the date and remarks recorded on the movement, and toasts', function () {
    $this->actingAs($this->ppc)
        ->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => 120.5, 'issued_on' => '11-09-2026', 'remarks' => 'Jigger 2'])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => __('production.issue.issued', ['qty' => '120.50', 'lot' => $this->lot->lot_no_oracle])]);

    $lot = $this->lot->fresh();
    $m = $lot->movements()->where('kind', 'issue')->firstOrFail();
    expect((float) $lot->greige_in_stock_oracle)->toBe(379.5)->and((float) $lot->qty_issued)->toBe(120.5)
        ->and($m->moved_at->format('d-m-Y'))->toBe('11-09-2026')->and($m->reason)->toBe('Jigger 2')->and($m->user_id)->toBe($this->ppc->id);
    $this->ledger->assertInvariant($lot);
});

it('reports the available quantity on the qty field when over-issuing', function () {
    $this->actingAs($this->ppc)->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => 600, 'issued_on' => '11-09-2026'])
        ->assertSessionHasErrors(['qty' => __('production.errors.insufficient_quantity', [
            'lot' => $this->lot->lot_no_oracle, 'position' => __('production.positions.stock'), 'available' => '500.00', 'requested' => '600.00',
        ])]);
    expect((float) $this->lot->fresh()->greige_in_stock_oracle)->toBe(500.0)->and($this->lot->movements()->where('kind', 'issue')->count())->toBe(0);
});

it('refuses a held lot on the lot field', function () {
    $held = receivedLot($this->ledger, ['state' => 'hold'], 10);
    $this->actingAs($this->ppc)->post('/production/issue', ['lot_id' => $held->id, 'qty' => 5, 'issued_on' => '11-09-2026'])
        ->assertSessionHasErrors(['lot_id' => __('production.errors.lot_not_open', ['lot' => $held->lot_no_oracle, 'state' => __('production.states.hold')])]);
    expect((float) $held->fresh()->greige_in_stock_oracle)->toBe(10.0);
});

it('validates the request before touching the ledger', function () {
    $this->actingAs($this->ppc)->post('/production/issue', ['lot_id' => 999999, 'qty' => 0, 'issued_on' => '2026-09-11', 'remarks' => str_repeat('x', 201)])
        ->assertSessionHasErrors(['lot_id', 'qty', 'issued_on', 'remarks']);
    $this->actingAs($this->ppc)->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => 1, 'issued_on' => now()->addDay()->format('d-m-Y')])
        ->assertSessionHasErrors(['issued_on']);
    $this->actingAs($this->ppc)->post('/production/issue/contract', ['contract_id' => 999999, 'issued_on' => 'today'])
        ->assertSessionHasErrors(['contract_id', 'issued_on']);
    expect($this->lot->movements()->where('kind', 'issue')->count())->toBe(0);
});

it('refuses quantities below 0.01 m or with more than two decimals', function () {
    foreach ([0.004, 120.505] as $qty) {
        $this->actingAs($this->ppc)->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => $qty, 'issued_on' => '11-09-2026'])
            ->assertSessionHasErrors('qty');
    }
    expect($this->lot->movements()->where('kind', 'issue')->count())->toBe(0)->and((float) $this->lot->fresh()->greige_in_stock_oracle)->toBe(500.0);
});

it('issues every open lot of a PO in one go from the screen and toasts the count', function () {
    $contract = $this->lot->contract;
    $held = receivedLot($this->ledger, ['contract_id' => $contract->id, 'state' => 'hold'], 10);
    $empty = receivedLot($this->ledger, ['contract_id' => $contract->id], 0);

    $this->actingAs($this->ppc)->post('/production/issue/contract', ['contract_id' => $contract->id, 'issued_on' => '12-09-2026'])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => __('production.issue.issued_contract', ['n' => 1, 'po' => $contract->po_no])]);

    expect((float) $this->lot->fresh()->greige_in_stock_oracle)->toBe(0.0)->and((float) $held->fresh()->greige_in_stock_oracle)->toBe(10.0)
        ->and($empty->movements()->count())->toBe(0)
        ->and($this->lot->movements()->where('kind', 'issue')->firstOrFail()->moved_at->format('Y-m-d H:i:s'))->toBe('2026-09-12 00:00:00');

    $this->actingAs($this->ppc)->post('/production/issue/contract', ['contract_id' => $contract->id, 'issued_on' => '12-09-2026'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'info', 'message' => __('production.issue.nothing_to_issue', ['n' => 0, 'po' => $contract->po_no])]);
});

it('reports nothing to issue when the PO was emptied between the pick and the confirm', function () {
    $this->issues->issue($this->lot, 500, $this->ppc);

    $this->actingAs($this->ppc)->post('/production/issue/contract', ['contract_id' => $this->lot->contract_id, 'issued_on' => '12-09-2026'])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'info', 'message' => __('production.issue.nothing_to_issue', ['n' => 0, 'po' => $this->lot->contract->po_no])]);
    expect($this->lot->movements()->where('kind', 'issue')->count())->toBe(1);
});

it('requires production.issue', function () {
    foreach (['viewer', 'dept_incharge', 'colour_lab', 'dispatch'] as $role) {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user)->get('/production/issue')->assertForbidden();
        $this->actingAs($user)->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => 1, 'issued_on' => '11-09-2026'])->assertForbidden();
        $this->actingAs($user)->post('/production/issue/contract', ['contract_id' => $this->lot->contract_id, 'issued_on' => '11-09-2026'])->assertForbidden();
    }
    expect((float) $this->lot->fresh()->greige_in_stock_oracle)->toBe(500.0);

    $manager = User::factory()->create()->assignRole('manager');
    $this->actingAs($manager)->get('/production/issue')->assertOk();
});

it('carries the balances and state on the lots LOV row', function () {
    $this->issues->issue($this->lot, 100, $this->ppc);
    $row = $this->actingAs($this->ppc)->getJson('/lov/lots?exact='.$this->lot->lot_no_oracle)->assertOk()->json('data.0');
    expect($row)->toMatchArray(['greige_in_stock_oracle' => 400.0, 'wip' => 100.0, 'finished' => 0.0, 'state' => 'open', 'contract_id' => $this->lot->contract_id])
        ->and($row['label'])->toContain(' · E1143/119 · 520 PLUM');
});

it('carries the open lots and their stock on the contracts LOV row', function () {
    $contract = $this->lot->contract;
    $partly = receivedLot($this->ledger, ['contract_id' => $contract->id], 300);
    $this->issues->issue($partly, 100, $this->ppc);
    receivedLot($this->ledger, ['contract_id' => $contract->id, 'state' => 'hold'], 10);
    receivedLot($this->ledger, ['contract_id' => $contract->id], 0);
    receivedLot($this->ledger, [], 40);

    $row = $this->actingAs($this->ppc)->getJson('/lov/contracts?exact='.$contract->po_no)->assertOk()->json('data.0');
    expect($row)->toMatchArray(['id' => $contract->id, 'open_lots' => 2, 'greige_in_stock_oracle' => 700.0]);

    $rows = $this->actingAs($this->ppc)->getJson('/lov/contracts?q='.$contract->po_no)->assertOk()->json('data');
    expect(collect($rows)->firstWhere('id', $contract->id))->toMatchArray(['open_lots' => 2, 'greige_in_stock_oracle' => 700.0]);
});

it('keeps only lots with greige in stock on the lots LOV when in_stock is set', function () {
    $empty = receivedLot($this->ledger, ['contract_id' => $this->lot->contract_id], 0);

    $all = collect($this->actingAs($this->ppc)->getJson('/lov/lots?exact='.$this->lot->contract->po_no)->assertOk()->json('data'))->pluck('id');
    $inStock = collect($this->actingAs($this->ppc)->getJson('/lov/lots?exact='.$this->lot->contract->po_no.'&filters[in_stock]=1')->assertOk()->json('data'))->pluck('id');

    expect($all->all())->toContain($this->lot->id, $empty->id)
        ->and($inStock->all())->toBe([$this->lot->id]);
});
