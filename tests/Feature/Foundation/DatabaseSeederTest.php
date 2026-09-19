<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Spatie\Permission\Models\Role;

it('seeds the whole database through DatabaseSeeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Role::findByName('ppc')->permissions)->not->toBeEmpty()
        ->and(User::where('email', env('ADMIN_EMAIL', 'admin@fabric.local'))->first()?->hasRole('admin'))->toBeTrue();
});
