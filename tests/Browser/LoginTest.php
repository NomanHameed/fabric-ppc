<?php

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Spec §11: a browser smoke test for the login screen — the real Fortify form, in a real browser,
 * landing on the dashboard with the shell rendered and no JavaScript errors on either page.
 */

it('logs in through the form and lands on the dashboard', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->create(['email' => 'login@fabric.local', 'locale' => 'en'])
        ->assignRole(Permissions::ROLE_PPC);

    visit('/login')
        ->assertSee(__('auth.pages.login.title'))
        ->assertNoJavaScriptErrors()
        ->fill('email', 'login@fabric.local')
        ->fill('password', 'password')
        ->click('@login-button')
        ->assertPathIs('/dashboard')
        ->assertSee(__('app.nav.dashboard'))
        ->assertNoJavaScriptErrors();
});

it('keeps a wrong password on the login screen with Fortify\'s message', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->create(['email' => 'login@fabric.local']);

    visit('/login')
        ->fill('email', 'login@fabric.local')
        ->fill('password', 'not-the-password')
        ->click('@login-button')
        ->assertPathIs('/login')
        ->assertSee(__('auth.failed'))
        ->assertNoJavaScriptErrors();
});
