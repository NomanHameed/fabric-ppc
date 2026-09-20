<?php

use App\Models\User;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\PartyAlias;
use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Foundation\Models\RouteTemplateStep;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use OwenIt\Auditing\Models\Audit;

beforeEach(function () {
    config(['audit.console' => true]); // Pest runs in the console; owen-it is off there by default
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
});

it('audits alias deletions (M-8)', function () {
    $party = Party::create(['name' => 'ALIASED', 'type' => 'brand']);
    $alias = $party->aliases()->create(['alias' => 'AL']);
    $this->actingAs($this->ppc)->put("/masters/parties/{$party->id}", ['name' => 'ALIASED', 'type' => 'brand', 'is_active' => true, 'aliases' => []])->assertRedirect();
    expect(PartyAlias::query()->whereKey($alias->id)->exists())->toBeFalse()
        ->and(Audit::where('auditable_type', PartyAlias::class)->where('auditable_id', $alias->id)->where('event', 'deleted')->exists())->toBeTrue();
});

it('audits route template step deletions (M-8)', function () {
    $ops = Operation::query()->limit(2)->pluck('id');
    $t = RouteTemplate::create(['name' => 'T', 'priority' => 0, 'is_active' => true]);
    $step = $t->steps()->create(['seq' => 10, 'operation_id' => $ops[0]]);
    $this->actingAs($this->ppc)->put("/masters/route-templates/{$t->id}", [
        'name' => 'T', 'priority' => 0, 'is_active' => true, 'steps' => [['operation_id' => $ops[1]]],
    ])->assertRedirect();
    expect(RouteTemplateStep::query()->whereKey($step->id)->exists())->toBeFalse()
        ->and(Audit::where('auditable_type', RouteTemplateStep::class)->where('auditable_id', $step->id)->where('event', 'deleted')->exists())->toBeTrue();
});
