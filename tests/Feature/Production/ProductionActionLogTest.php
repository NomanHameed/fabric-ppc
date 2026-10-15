<?php

/*
 * The production half of the action log.
 *
 * ActionKeys has defined `lot.issued`, `lot.moved`, `lot.held`, `lot.released`, `colour.approved` and
 * `colour.rejected` since the Activity module was built, but until now only DemoProductionSeeder ever wrote
 * them: on a real install the action log and LotTimelineService::fromUserActions() were empty for every lot
 * the mill actually worked on. These tests are what keeps them wired up.
 */

use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Timeline\TimelineEventType;
use Carbon\CarbonImmutable;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->ledger = app(LedgerService::class);
    $this->lot = Lot::factory()->create(['po_type_oracle' => 'HALF BLEACH', 'greige_received_oracle' => 500, 'clr_status_oracle' => 'APPROVED']);
    app(LotRouteService::class)->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 500);
});

it('records lot.issued when a lot is issued over HTTP, and shows it on the timeline', function () {
    $this->actingAs($this->ppc)->from('/production/issue')->post('/production/issue', [
        'lot_id' => $this->lot->id,
        'qty' => 120.5,
        'issued_on' => CarbonImmutable::today()->format('d-m-Y'),
        'remarks' => 'Jigger 2',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $action = UserAction::query()->where('action', ActionKeys::LOT_ISSUED)->sole();

    expect($action->user_id)->toBe($this->ppc->id)
        ->and($action->subject_type)->toBe($this->lot->getMorphClass())
        ->and($action->subject_id)->toBe($this->lot->id)
        ->and($action->description)->toContain($this->lot->lot_no_oracle)
        ->and($action->description)->toContain('120.5')
        ->and($action->device_id)->not->toBeNull();

    $timeline = $this->actingAs($this->ppc)->getJson("/production/lots/{$this->lot->id}/timeline")
        ->assertOk()->json('events');

    $actions = collect($timeline)->where('type', TimelineEventType::ACTION);

    expect($actions)->toHaveCount(1)
        ->and($actions->first()['title'])->toContain($this->lot->lot_no_oracle)
        ->and($actions->first()['actor'])->toBe($this->ppc->name);
});

it('records one lot.issued per lot on a bulk contract issue, after the outer transaction', function () {
    $contract = Contract::query()->findOrFail($this->lot->contract_id);
    $second = Lot::factory()->create(['contract_id' => $contract->id, 'greige_received_oracle' => 200]);
    $this->ledger->post($second, 'import', null, Position::stock(), 0, 200);

    $this->actingAs($this->ppc)->from('/production/issue')->post('/production/issue/contract', [
        'contract_id' => $contract->id,
        'issued_on' => CarbonImmutable::today()->format('d-m-Y'),
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(UserAction::query()->where('action', ActionKeys::LOT_ISSUED)->count())->toBe(2)
        ->and(UserAction::query()->where('action', ActionKeys::LOT_ISSUED)->pluck('subject_id')->sort()->values()->all())
        ->toBe(collect([$this->lot->id, $second->id])->sort()->values()->all());
});

it('records lot.held, lot.released and lot.cancelled with the reason', function () {
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'PARTY_HOLD'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/release")->assertSessionHasNoErrors();

    $held = UserAction::query()->where('action', ActionKeys::LOT_HELD)->sole();
    $released = UserAction::query()->where('action', ActionKeys::LOT_RELEASED)->sole();

    expect($held->subject_id)->toBe($this->lot->id)
        ->and($held->user_id)->toBe($this->ppc->id)
        ->and($held->description)->toContain('PARTY_HOLD')
        ->and($released->subject_id)->toBe($this->lot->id);

    $cancellable = Lot::factory()->create(['greige_received_oracle' => 10]);
    $this->actingAs($this->ppc)->post("/production/lots/{$cancellable->id}/cancel", ['reason' => 'PARTY_HOLD'])
        ->assertSessionHasNoErrors();

    expect(UserAction::query()->where('action', ActionKeys::LOT_CANCELLED)->sole()->subject_id)
        ->toBe($cancellable->id);
});

it('records nothing when a state change is refused', function () {
    // Already open: releasing it is an invalid transition.
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/release")
        ->assertSessionHasErrors('state');

    expect(UserAction::query()->whereIn('action', [ActionKeys::LOT_RELEASED, ActionKeys::LOT_HELD])->count())->toBe(0);
});

it('records lot.moved when a production sheet posts a line', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 500, 500);

    $this->actingAs($this->ppc)->from('/production/sheets')->post('/production/sheets', [
        'sheet_date' => CarbonImmutable::today()->format('d-m-Y'),
        'shift_id' => Shift::where('code', 'A')->value('id'),
        'department_id' => Department::where('code', 'SINGEING')->value('id'),
        'machine_id' => Machine::where('code', 'SINGEING')->value('id'),
        'downtime_minutes' => 0,
        'remarks' => null,
        'lines' => [[
            'lot_id' => $this->lot->id,
            'operation_id' => Operation::where('name', 'SINGING')->value('id'),
            'qty_in' => 100, 'qty_out' => 99,
        ]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $moved = UserAction::query()->where('action', ActionKeys::LOT_MOVED)->sole();

    expect($moved->subject_id)->toBe($this->lot->id)
        ->and($moved->user_id)->toBe($this->ppc->id)
        ->and($moved->description)->toContain($this->lot->lot_no_oracle)
        ->and($moved->context['from'])->toBe(__('production.positions.issued'))
        ->and($moved->context['to'])->toBe('SINGING');
});

it('records colour.approved and colour.rejected from the Lots screen', function () {
    $approvedCode = LookupValue::query()->list('colour_status')->where('is_active', true)->get()
        ->first(fn (LookupValue $v) => (bool) ($v->meta['approved'] ?? false));
    $unapprovedCode = LookupValue::query()->list('colour_status')->where('is_active', true)->get()
        ->first(fn (LookupValue $v) => ! (bool) ($v->meta['approved'] ?? false));

    expect($approvedCode)->not->toBeNull()->and($unapprovedCode)->not->toBeNull();

    $lot = Lot::factory()->create(['clr_status_oracle' => null, 'color_oracle_name' => '520 PLUM']);

    $this->actingAs($this->ppc)->put("/production/lots/{$lot->id}", ['clr_status_oracle' => $approvedCode->code])
        ->assertSessionHasNoErrors();

    $approved = UserAction::query()->where('action', ActionKeys::COLOUR_APPROVED)->sole();
    expect($approved->subject_id)->toBe($lot->id)
        ->and($approved->description)->toContain('520 PLUM');

    $this->actingAs($this->ppc)->put("/production/lots/{$lot->id}", ['clr_status_oracle' => $unapprovedCode->code])
        ->assertSessionHasNoErrors();

    expect(UserAction::query()->where('action', ActionKeys::COLOUR_REJECTED)->sole()->subject_id)->toBe($lot->id);

    // Saving the same status again is not a new decision.
    $this->actingAs($this->ppc)->put("/production/lots/{$lot->id}", ['clr_status_oracle' => $unapprovedCode->code])
        ->assertSessionHasNoErrors();

    expect(UserAction::query()->where('action', ActionKeys::COLOUR_REJECTED)->count())->toBe(1);
});
