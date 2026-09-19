<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

it('renders the dashboard for every role', function (string $role) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->has('auth.permissions'));
})->with(['admin', 'ppc', 'manager', 'dept_incharge', 'colour_lab', 'dispatch', 'viewer']);
