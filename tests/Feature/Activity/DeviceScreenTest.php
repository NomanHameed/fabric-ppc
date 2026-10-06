<?php

/*
 * The two reads behind the computer-name prompt: the small JSON probe that decides whether the strip appears,
 * and the "this computer" screen it links to. Both describe the terminal the request came from and nothing
 * else. Naming is covered by DeviceTrackingTest; this is about the screens.
 */

use App\Models\User;
use App\Modules\Activity\Models\UserDevice;
use App\Modules\Activity\Services\DeviceRegistrar;
use App\Modules\Foundation\Access\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // A viewer holds none of the activity permissions: naming your own terminal must need no permission at all.
    $this->user = User::factory()->create()->assignRole(Permissions::ROLE_VIEWER);
});

it('reports an unnamed terminal, which is what makes the prompt appear', function () {
    $this->actingAs($this->user)->getJson('/devices/current')
        ->assertOk()
        ->assertJsonPath('named', false)
        ->assertJsonPath('computer_name', null)
        ->assertJsonStructure(['device_id', 'computer_name', 'named']);
});

it('stops reporting the terminal as unnamed once it has a name', function () {
    $deviceId = $this->actingAs($this->user)->get('/devices')
        ->getCookie(DeviceRegistrar::COOKIE)->getValue();

    app()->forgetScopedInstances();

    $this->actingAs($this->user)
        ->withCookie(DeviceRegistrar::COOKIE, $deviceId)
        ->put('/devices/name', ['computer_name' => 'PPC-OFFICE-09'])->assertRedirect();

    app()->forgetScopedInstances();

    // getJson sends no cookies unless credentials are asked for, which is what a same-origin fetch() does.
    $this->actingAs($this->user)
        ->withCredentials()
        ->withCookie(DeviceRegistrar::COOKIE, $deviceId)
        ->getJson('/devices/current')
        ->assertOk()
        ->assertJsonPath('named', true)
        ->assertJsonPath('computer_name', 'PPC-OFFICE-09');

    expect(UserDevice::query()->count())->toBe(1);
});

it('renders the this-computer screen for the terminal the request came from', function () {
    $this->actingAs($this->user)->get('/devices')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('activity/Device')
            ->has('device.device_id')
            ->where('device.computer_name', null)
            ->has('device.last_seen_at')
        );

    expect(UserDevice::query()->count())->toBe(1);
});

it('tells the screen about one device only — the caller\'s own', function () {
    UserDevice::factory()->create(['computer_name' => 'LAB-BENCH']);

    $this->actingAs($this->user)->get('/devices')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('device')->missing('devices'));

    $this->actingAs($this->user)->getJson('/devices/current')
        ->assertOk()
        ->assertJsonMissing(['computer_name' => 'LAB-BENCH']);
});

it('refuses both device routes to a guest', function () {
    $this->get('/devices')->assertRedirect('/login');
    $this->getJson('/devices/current')->assertUnauthorized();
});
