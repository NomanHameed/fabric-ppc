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
    $this->lot = Lot::factory()->create(['greige_qty' => 500, 'design' => 'E1143/119', 'colour' => '520 PLUM']);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 500);
});

function receivedLot(LedgerService $ledger, array $attrs, float $qty): Lot
{
    $lot = Lot::factory()->create($attrs + ['greige_qty' => $qty]);
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
    expect((float) $lot->stock_qty)->toBe(0.0)->and((float) $lot->issued_qty)->toBe(500.0)->and((float) $lot->wip_qty)->toBe(500.0)
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

    expect((float) $this->lot->fresh()->stock_qty)->toBe(0.0)->and((float) $this->lot->fresh()->issued_qty)->toBe(500.0)
        ->and((float) $partly->fresh()->stock_qty)->toBe(0.0)->and((float) $partly->fresh()->issued_qty)->toBe(300.0)
        ->and((float) $held->fresh()->stock_qty)->toBe(10.0)->and((float) $cancelled->fresh()->stock_qty)->toBe(10.0)
        ->and($empty->movements()->count())->toBe(0)->and((float) $other->fresh()->stock_qty)->toBe(40.0)
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
            ->where('recent.0.remarks', 'second')->where('recent.0.qty', 25)->where('recent.0.lot_no', $this->lot->lot_no)
            ->where('recent.0.po_no', $this->lot->contract->po_no)->where('recent.0.design', 'E1143/119')->where('recent.0.colour', '520 PLUM')
            ->where('recent.0.stock_qty', 425)->where('recent.0.reversal', false)->where('recent.0.user', $this->ppc->name)
            ->where('recent.0.moved_at', now()->format('d-m-Y'))
            ->where('recent.1.remarks', 'first'));
});

it('issues part of a lot with the date and remarks recorded on the movement, and toasts', function () {
    $this->actingAs($this->ppc)
        ->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => 120.5, 'issued_on' => '11-09-2026', 'remarks' => 'Jigger 2'])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => __('production.issue.issued', ['qty' => '120.50', 'lot' => $this->lot->lot_no])]);

    $lot = $this->lot->fresh();
    $m = $lot->movements()->where('kind', 'issue')->firstOrFail();
    expect((float) $lot->stock_qty)->toBe(379.5)->and((float) $lot->issued_qty)->toBe(120.5)
        ->and($m->moved_at->format('d-m-Y'))->toBe('11-09-2026')->and($m->reason)->toBe('Jigger 2')->and($m->user_id)->toBe($this->ppc->id);
    $this->ledger->assertInvariant($lot);
});

it('reports the available quantity on the qty field when over-issuing', function () {
    $this->actingAs($this->ppc)->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => 600, 'issued_on' => '11-09-2026'])
        ->assertSessionHasErrors(['qty' => __('production.errors.insufficient_quantity', [
            'lot' => $this->lot->lot_no, 'position' => __('production.positions.stock'), 'available' => '500.00', 'requested' => '600.00',
        ])]);
    expect((float) $this->lot->fresh()->stock_qty)->toBe(500.0)->and($this->lot->movements()->where('kind', 'issue')->count())->toBe(0);
});

it('refuses a held lot on the lot field', function () {
    $held = receivedLot($this->ledger, ['state' => 'hold'], 10);
    $this->actingAs($this->ppc)->post('/production/issue', ['lot_id' => $held->id, 'qty' => 5, 'issued_on' => '11-09-2026'])
        ->assertSessionHasErrors(['lot_id' => __('production.errors.lot_not_open', ['lot' => $held->lot_no, 'state' => __('production.states.hold')])]);
    expect((float) $held->fresh()->stock_qty)->toBe(10.0);
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

it('issues every open lot of a PO in one go from the screen and toasts the count', function () {
    $contract = $this->lot->contract;
    $held = receivedLot($this->ledger, ['contract_id' => $contract->id, 'state' => 'hold'], 10);
    $empty = receivedLot($this->ledger, ['contract_id' => $contract->id], 0);

    $this->actingAs($this->ppc)->post('/production/issue/contract', ['contract_id' => $contract->id, 'issued_on' => '12-09-2026'])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => __('production.issue.issued_contract', ['n' => 1, 'po' => $contract->po_no])]);

    expect((float) $this->lot->fresh()->stock_qty)->toBe(0.0)->and((float) $held->fresh()->stock_qty)->toBe(10.0)
        ->and($empty->movements()->count())->toBe(0)
        ->and($this->lot->movements()->where('kind', 'issue')->firstOrFail()->moved_at->format('Y-m-d H:i:s'))->toBe('2026-09-12 00:00:00');

    $this->actingAs($this->ppc)->post('/production/issue/contract', ['contract_id' => $contract->id, 'issued_on' => '12-09-2026'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'info', 'message' => __('production.issue.nothing_to_issue', ['n' => 0, 'po' => $contract->po_no])]);
});

it('requires production.issue', function () {
    $viewer = User::factory()->create()->assignRole('viewer');
    $this->actingAs($viewer)->get('/production/issue')->assertForbidden();
    $this->actingAs($viewer)->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => 1, 'issued_on' => '11-09-2026'])->assertForbidden();
    $this->actingAs($viewer)->post('/production/issue/contract', ['contract_id' => $this->lot->contract_id, 'issued_on' => '11-09-2026'])->assertForbidden();
    expect((float) $this->lot->fresh()->stock_qty)->toBe(500.0);

    $manager = User::factory()->create()->assignRole('manager');
    $this->actingAs($manager)->get('/production/issue')->assertOk();
});

it('carries the balances and state on the lots LOV row', function () {
    $this->issues->issue($this->lot, 100, $this->ppc);
    $row = $this->actingAs($this->ppc)->getJson('/lov/lots?exact='.$this->lot->lot_no)->assertOk()->json('data.0');
    expect($row)->toMatchArray(['stock_qty' => 400.0, 'wip_qty' => 100.0, 'finished_qty' => 0.0, 'state' => 'open', 'contract_id' => $this->lot->contract_id])
        ->and($row['label'])->toContain(' · E1143/119 · 520 PLUM');
});
