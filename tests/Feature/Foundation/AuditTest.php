<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;

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
