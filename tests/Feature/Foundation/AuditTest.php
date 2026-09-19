<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Setting;
use OwenIt\Auditing\Models\Audit;

it('records who changed a master and the old and new values', function () {
    config(['audit.console' => true]);
    $user = User::factory()->create();
    $this->actingAs($user);

    $dept = Department::create(['code' => 'TEST', 'name_en' => 'Test', 'sort' => 99]);
    $dept->update(['name_en' => 'Test Renamed']);

    $audit = $dept->audits()->latest('id')->first();
    expect($audit->event)->toBe('updated')
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->old_values)->toBe(['name_en' => 'Test'])
        ->and($audit->new_values)->toBe(['name_en' => 'Test Renamed']);
});

it('saves a setting with auditing on and writes no audit row for it', function () {
    config(['audit.console' => true]);
    $this->actingAs(User::factory()->create());

    Setting::put('company_name', 'X');

    expect(Setting::get('company_name'))->toBe('X')
        ->and(Audit::query()->where('auditable_type', Setting::class)->count())->toBe(0);
});
