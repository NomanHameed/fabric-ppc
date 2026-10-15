<?php

use App\Models\User;
use App\Modules\Production\Exceptions\LotNotOpen;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotStateService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->lot = Lot::factory()->create(['greige_received_oracle' => 50]);
    $this->ledger = app(LedgerService::class);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 50);
});

it('holds, releases and cancels with audited reasons', function () {
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'PARTY_HOLD'])
        ->assertSessionHasNoErrors()->assertRedirect();
    $lot = $this->lot->fresh();
    expect($lot->state)->toBe('hold')->and($lot->hold_reason)->toBe('PARTY_HOLD')->and($lot->status_oracle)->toBe('HOLD/')
        ->and($lot->state_changed_at)->not->toBeNull();
    $audit = $lot->audits()->latest('id')->first();
    expect($audit->event)->toBe('updated')->and($audit->user_id)->toBe($this->ppc->id)
        ->and($audit->old_values['state'])->toBe('open')->and($audit->new_values['state'])->toBe('hold')
        ->and($audit->new_values['hold_reason'])->toBe('PARTY_HOLD');

    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/release")->assertSessionHasNoErrors();
    $lot = $this->lot->fresh();
    expect($lot->state)->toBe('open')->and($lot->hold_reason)->toBeNull()->and($lot->status_oracle)->toBeNull();

    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/cancel", ['reason' => 'OTHER'])->assertSessionHasNoErrors();
    $lot = $this->lot->fresh();
    expect($lot->state)->toBe('cancelled')->and($lot->cancel_reason)->toBe('OTHER')->and($lot->status_oracle)->toBe('CANCELLED/')
        ->and($lot->audits()->count())->toBe(3);
});

it('flashes a toast on success', function () {
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'OTHER'])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => __('production.state.held', ['lot' => $this->lot->lot_no_oracle])]);
});

it('rejects unknown and inactive reasons and impossible transitions', function () {
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'NOPE'])
        ->assertSessionHasErrors(['state' => __('production.errors.unknown_reason', ['code' => 'NOPE'])]);
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'SHRINKAGE']) // a loss_reason, not a hold_reason
        ->assertSessionHasErrors('state');
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", [])->assertSessionHasErrors('reason');
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/release")
        ->assertSessionHasErrors(['state' => __('production.errors.invalid_state_change', ['lot' => $this->lot->lot_no_oracle, 'state' => __('production.states.open')])]);
    expect($this->lot->fresh()->state)->toBe('open')->and($this->lot->fresh()->audits()->count())->toBe(0);
});

it('refuses hold and cancel on a closed lot, and a second hold on a held lot', function () {
    $this->lot->update(['state' => 'closed']);
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'OTHER'])->assertSessionHasErrors('state');
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/cancel", ['reason' => 'OTHER'])->assertSessionHasErrors('state');
    expect($this->lot->fresh()->state)->toBe('closed');

    $this->lot->update(['state' => 'hold', 'hold_reason' => 'OTHER']);
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'PARTY_HOLD'])->assertSessionHasErrors('state');
    expect($this->lot->fresh()->hold_reason)->toBe('OTHER');
});

it('cancels a held lot and clears the hold reason', function () {
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'PARTY_HOLD'])->assertSessionHasNoErrors();
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/cancel", ['reason' => 'QUALITY_ISSUE'])->assertSessionHasNoErrors();
    $lot = $this->lot->fresh();
    expect($lot->state)->toBe('cancelled')->and($lot->hold_reason)->toBeNull()->and($lot->cancel_reason)->toBe('QUALITY_ISSUE');
});

it('refuses to cancel a lot with metres on the floor or finished', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 20, 20);
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/cancel", ['reason' => 'OTHER'])
        ->assertSessionHasErrors(['state' => __('production.errors.cancel_has_wip', ['lot' => $this->lot->lot_no_oracle])]);
    expect($this->lot->fresh()->state)->toBe('open');

    $this->ledger->post($this->lot, 'process', Position::issued(), Position::finished(), 20, 20);
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/cancel", ['reason' => 'OTHER'])->assertSessionHasErrors('state');

    // back off the floor entirely: cancel is allowed again
    $this->ledger->post($this->lot, 'loss', Position::finished(), Position::loss(), 20, 20);
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/cancel", ['reason' => 'OTHER'])->assertSessionHasNoErrors();
    expect($this->lot->fresh()->state)->toBe('cancelled');
});

it('stops floor movements on a held or cancelled lot but still takes adjustments and losses', function () {
    app(LotStateService::class)->hold($this->lot, 'QUALITY_ISSUE', $this->ppc);
    expect($this->lot->state)->toBe('hold')->and($this->lot->status_oracle)->toBe('HOLD/'); // the caller's instance is current
    expect(fn () => $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 10, 10))->toThrow(LotNotOpen::class);
    $this->ledger->post($this->lot, 'adjustment', null, Position::stock(), 0, 5);
    $this->ledger->post($this->lot, 'loss', Position::stock(), Position::loss(), 15, 15);
    expect($this->lot->fresh()->greige_in_stock_oracle)->toBe('40.00')->and($this->lot->fresh()->status_oracle)->toBe('HOLD/');

    app(LotStateService::class)->release($this->lot, $this->ppc);
    app(LotStateService::class)->cancel($this->lot, 'OTHER', $this->ppc);
    expect(fn () => $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 10, 10))->toThrow(LotNotOpen::class);
});

it('attributes the audit to the given user outside a request', function () {
    app(LotStateService::class)->hold($this->lot, 'OTHER', $this->ppc);
    expect($this->lot->audits()->latest('id')->first()->user_id)->toBe($this->ppc->id);
});

it('returns 404 for an unknown or malformed lot id', function () {
    $this->actingAs($this->ppc)->post('/production/lots/999999/hold', ['reason' => 'OTHER'])->assertNotFound();
    $this->actingAs($this->ppc)->post('/production/lots/abc/hold', ['reason' => 'OTHER'])->assertNotFound();
});

it('requires production.hold', function () {
    $this->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'OTHER'])->assertRedirect('/login');
    $this->actingAs($this->viewer)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'OTHER'])->assertForbidden();
    $this->actingAs($this->viewer)->post("/production/lots/{$this->lot->id}/release")->assertForbidden();
    $this->actingAs($this->viewer)->post("/production/lots/{$this->lot->id}/cancel", ['reason' => 'OTHER'])->assertForbidden();
    expect($this->lot->fresh()->state)->toBe('open');
});
