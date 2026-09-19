<?php

use App\Models\User;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\RouteTemplate;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
});

it('lists templates with steps', function () {
    $this->actingAs($this->ppc)->get('/masters/route-templates')->assertOk()
        ->assertInertia(fn ($page) => $page->component('masters/RouteTemplates')->has('templates', 4)
            ->where('templates.0.steps.0.operation', fn ($v) => is_string($v)));
});

it('saves a template with renumbered steps', function () {
    $ops = Operation::whereIn('name', ['SINGING', 'DRY', 'SANFORIZE'])->pluck('id', 'name');
    $this->actingAs($this->ppc)->post('/masters/route-templates', [
        'name' => 'Quick', 'po_type' => 'PFD', 'quality_pattern' => null, 'priority' => 5, 'is_active' => true,
        'steps' => [['operation_id' => $ops['SINGING']], ['operation_id' => $ops['DRY']], ['operation_id' => $ops['SANFORIZE']]],
    ])->assertRedirect();
    $tpl = RouteTemplate::where('name', 'Quick')->firstOrFail();
    expect($tpl->steps->pluck('seq')->all())->toBe([10, 20, 30])
        ->and($tpl->steps->pluck('operation_id')->all())->toBe([$ops['SINGING'], $ops['DRY'], $ops['SANFORIZE']]);
});

it('requires at least one step', function () {
    $this->actingAs($this->ppc)->post('/masters/route-templates', ['name' => 'Empty', 'priority' => 0, 'steps' => []])
        ->assertSessionHasErrors('steps');
});

it('replaces steps on update', function () {
    $tpl = RouteTemplate::where('name', 'Half Bleach')->first();
    $dry = Operation::where('name', 'DRY')->value('id');
    $this->actingAs($this->ppc)->put("/masters/route-templates/{$tpl->id}", [
        'name' => 'Half Bleach', 'po_type' => 'HALF BLEACH', 'priority' => 10, 'is_active' => true, 'steps' => [['operation_id' => $dry]],
    ])->assertRedirect();
    expect($tpl->fresh()->steps)->toHaveCount(1);
});

it('filters templates by name in query mode', function () {
    $this->actingAs($this->ppc)->get('/masters/route-templates?q[name]=Dyed%25')->assertOk()
        ->assertInertia(fn ($page) => $page->component('masters/RouteTemplates')->has('templates', 1)
            ->where('templates.0.name', 'Dyed - Standard'));
});
