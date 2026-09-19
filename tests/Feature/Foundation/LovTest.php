<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Operation;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\PartySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, PartySeeder::class]);
    $this->user = User::factory()->create();
    $this->user->assignRole('viewer');
});

it('requires authentication', function () {
    $this->getJson('/lov/departments')->assertUnauthorized();
});

it('returns columns and rows', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/departments')->assertOk();
    expect($res->json('columns.0.key'))->toBe('code')
        ->and($res->json('data'))->toHaveCount(12)
        ->and($res->json('data.0'))->toHaveKeys(['id', 'code', 'label']);
});

it('searches case-insensitively', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/departments?q=sanfo')->assertOk();
    expect(collect($res->json('data'))->pluck('code')->all())->toBe(['SANFO_CALENDER']);
});

it('matches exact codes', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/departments?exact=drying')->assertOk();
    expect($res->json('data'))->toHaveCount(1);
});

it('filters operations by department and kind', function () {
    $dept = Department::where('code', 'MERCERIZING')->first();
    $res = $this->actingAs($this->user)
        ->getJson("/lov/operations?filters[department_id]={$dept->id}&filters[kind]=reprocess")->assertOk();
    expect(collect($res->json('data'))->pluck('label')->sort()->values()->all())->toBe(['RE MERCERIZING', 'RE WASH']);
});

it('excludes inactive records', function () {
    Operation::where('name', 'RE WASH')->update(['is_active' => false]);
    $dept = Department::where('code', 'MERCERIZING')->first();
    $res = $this->actingAs($this->user)->getJson("/lov/operations?filters[department_id]={$dept->id}&filters[kind]=reprocess");
    expect($res->json('data'))->toHaveCount(1);
});

it('filters machines by department via the pivot', function () {
    $dept = Department::where('code', 'BATCH_STENTER')->first();
    $res = $this->actingAs($this->user)->getJson("/lov/machines?filters[department_id]={$dept->id}");
    expect(collect($res->json('data'))->pluck('code')->sort()->values()->all())->toBe(['STENTER-1', 'STENTER-2']);
});

it('finds parties by alias', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/parties?exact=M-B');
    expect($res->json('data.0.label'))->toBe('MARIA-B');
});

it('requires list_key for lookups and returns 404 for unknown lovs', function () {
    $this->actingAs($this->user)->getJson('/lov/lookup')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filters.list_key' => 'Choose which list to show']);
    $this->actingAs($this->user)->getJson('/lov/lookup?filters[list_key]=loss_reason')->assertOk()->assertJsonCount(5, 'data');
    $this->actingAs($this->user)->getJson('/lov/nope')->assertNotFound();
});

it('caps results at 50', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/parties');
    expect($res->json('data'))->toHaveCount(50);
});
