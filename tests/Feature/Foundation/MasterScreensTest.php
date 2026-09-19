<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Machine;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->viewer = User::factory()->create()->assignRole('viewer');
});

it('lists a master with field metadata', function () {
    $this->actingAs($this->viewer)->get('/masters/departments')->assertOk()
        ->assertInertia(fn ($page) => $page->component('masters/MasterForm')
            ->where('resource.key', 'departments')
            ->where('resource.canEdit', false)
            ->has('resource.fields', 5)
            ->has('rows', 12));
});

it('applies query criteria', function () {
    $this->actingAs($this->viewer)->get('/masters/departments?q[code]=S%25')
        ->assertInertia(fn ($page) => $page->has('rows', 3)); // SANFO_CALENDER, SINGEING, STITCHING
});

it('creates, updates and deletes a record', function () {
    $this->actingAs($this->ppc)->post('/masters/departments', ['code' => 'QA', 'name_en' => 'Quality', 'sort' => 130, 'is_active' => true])
        ->assertRedirect();
    $dept = Department::where('code', 'QA')->firstOrFail();

    $this->actingAs($this->ppc)->put("/masters/departments/{$dept->id}", ['code' => 'QA', 'name_en' => 'Quality Control', 'sort' => 130, 'is_active' => true])
        ->assertRedirect();
    expect($dept->fresh()->name_en)->toBe('Quality Control');

    $this->actingAs($this->ppc)->delete("/masters/departments/{$dept->id}")->assertRedirect();
    expect(Department::where('code', 'QA')->exists())->toBeFalse();
});

it('validates unique codes', function () {
    $this->actingAs($this->ppc)->post('/masters/departments', ['code' => 'DRYING', 'name_en' => 'Dup', 'sort' => 1])
        ->assertSessionHasErrors('code');
});

it('refuses to delete a record in use', function () {
    $dept = Department::where('code', 'DRYING')->first();
    $this->actingAs($this->ppc)->delete("/masters/departments/{$dept->id}")->assertSessionHasErrors('record');
    expect(Department::whereKey($dept->id)->exists())->toBeTrue();
});

it('forbids edits without masters.manage', function () {
    $this->actingAs($this->viewer)->post('/masters/departments', ['code' => 'X', 'name_en' => 'X', 'sort' => 1])->assertForbidden();
});

it('syncs machine departments', function () {
    $d1 = Department::where('code', 'DRYING')->first();
    $d2 = Department::where('code', 'CURING')->first();
    $this->actingAs($this->ppc)->post('/masters/machines', ['code' => 'CURE-1', 'name' => 'Curing Oven', 'is_active' => true, 'departments' => [$d1->id, $d2->id]])
        ->assertRedirect();
    expect(Machine::where('code', 'CURE-1')->first()->departments->pluck('id')->sort()->values()->all())
        ->toBe(collect([$d1->id, $d2->id])->sort()->values()->all());
});

it('maps the lookup approved flag into meta', function () {
    $this->actingAs($this->ppc)->post('/masters/lookups', [
        'list_key' => 'colour_status', 'code' => 'LAB_OK', 'label_en' => 'Lab OK', 'sort' => 200, 'approved' => true, 'is_active' => true,
    ])->assertRedirect();
    expect(LookupValue::where('code', 'LAB_OK')->first()->meta)->toBe(['approved' => true]);
});

it('returns 404 for an unknown master', function () {
    $this->actingAs($this->viewer)->get('/masters/nope')->assertNotFound();
});
