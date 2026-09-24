<?php

use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Models\UserDevice;
use App\Modules\Activity\Services\DeviceRegistrar;
use App\Modules\Activity\Support\ActionKeys;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create()->assignRole('ppc');
});

it('issues a device id on the first visit and keeps it afterwards', function () {
    $response = $this->actingAs($this->user)->get('/dashboard')->assertOk();

    $cookie = $response->getCookie(DeviceRegistrar::COOKIE);
    expect($cookie)->not->toBeNull();

    $deviceId = $cookie->getValue();
    expect(UserDevice::query()->where('device_id', $deviceId)->exists())->toBeTrue();

    // Second visit carrying the cookie: the same device is reused, not a second one. The registrar is
    // request-scoped and the test container outlives a single call, so drop it first — otherwise the memoised
    // device answers and the cookie is never read at all.
    app()->forgetScopedInstances();

    $this->actingAs($this->user)
        ->withCookie(DeviceRegistrar::COOKIE, $deviceId)
        ->get('/dashboard')->assertOk();

    expect(UserDevice::query()->count())->toBe(1)
        ->and(UserDevice::query()->sole()->device_id)->toBe($deviceId);
});

it('refuses a device id the client made up and starts a fresh one', function () {
    $this->actingAs($this->user)
        ->withCookie(DeviceRegistrar::COOKIE, 'not-a-ulid')
        ->get('/dashboard')->assertOk();

    $device = UserDevice::query()->sole();
    expect($device->device_id)->not->toBe('not-a-ulid')
        ->and(Str::isUlid($device->device_id))->toBeTrue();
});

it('records the device against the signed-in user and the request ip', function () {
    $this->actingAs($this->user)->get('/dashboard');

    $device = UserDevice::query()->sole();
    expect($device->user_id)->toBe($this->user->id)
        ->and($device->last_ip_address)->not->toBeNull()
        ->and($device->first_seen_at)->not->toBeNull()
        ->and($device->last_seen_at)->not->toBeNull();
});

/*
 * Anonymous requests must not be able to write rows. TrackDevice runs in the `web` group for guests too, and
 * `firstOrCreate` used to mint a `user_devices` row (plus an `audits` row) on every cookieless request — so a
 * crawler, a health check or a curl loop against a public route filled both tables, unauthenticated and
 * unbounded. The cookie is still issued, so the terminal keeps its identity into the session that matters.
 */
it('gives a guest terminal a cookie but creates no row for it', function () {
    $response = $this->get('/login')->assertOk();

    expect($response->getCookie(DeviceRegistrar::COOKIE))->not->toBeNull()
        ->and(UserDevice::query()->count())->toBe(0);
});

it('creates nothing at all for a flood of cookieless guest requests', function () {
    foreach (range(1, 10) as $ignored) {
        $this->get('/i18n/en.json')->assertOk();
    }

    expect(UserDevice::query()->count())->toBe(0)
        ->and(DB::table('audits')->where('auditable_type', UserDevice::class)->count())->toBe(0);
});

it('adopts the cookie a guest was given once that visitor signs in', function () {
    $deviceId = $this->get('/login')->getCookie(DeviceRegistrar::COOKIE)->getValue();

    app()->forgetScopedInstances();

    $this->actingAs($this->user)
        ->withCookie(DeviceRegistrar::COOKIE, $deviceId)
        ->get('/dashboard')->assertOk();

    expect(UserDevice::query()->count())->toBe(1)
        ->and(UserDevice::query()->sole()->device_id)->toBe($deviceId)
        ->and(UserDevice::query()->sole()->user_id)->toBe($this->user->id);
});

it('does not audit the creation of a device row', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();

    expect(UserDevice::query()->count())->toBe(1)
        ->and(DB::table('audits')->where('auditable_type', UserDevice::class)->count())->toBe(0);
});

it('registers a computer name for the current device and logs it', function () {
    $response = $this->actingAs($this->user)->get('/dashboard');
    $deviceId = $response->getCookie(DeviceRegistrar::COOKIE)->getValue();

    app()->forgetScopedInstances();

    $this->actingAs($this->user)
        ->withCookie(DeviceRegistrar::COOKIE, $deviceId)
        ->put('/devices/name', ['computer_name' => 'STENTER-FLOOR'])
        ->assertRedirect();

    $device = UserDevice::query()->where('device_id', $deviceId)->sole();
    expect($device->computer_name)->toBe('STENTER-FLOOR');

    $action = UserAction::query()->where('action', ActionKeys::DEVICE_NAMED)->sole();
    expect($action->subject_id)->toBe($device->id)
        ->and($action->device_id)->toBe($deviceId)
        ->and($action->description)->toContain('STENTER-FLOOR');
});

it('validates the computer name', function () {
    $this->actingAs($this->user)->put('/devices/name', ['computer_name' => ''])
        ->assertSessionHasErrors('computer_name');
    $this->actingAs($this->user)->put('/devices/name', ['computer_name' => str_repeat('x', 61)])
        ->assertSessionHasErrors('computer_name');
});

it('cannot rename somebody else\'s device: only the cookie decides which device is named', function () {
    $other = UserDevice::factory()->create(['computer_name' => 'DISPATCH-DESK']);

    $this->actingAs($this->user)->put('/devices/name', [
        'computer_name' => 'HIJACKED', 'device_id' => $other->device_id, 'id' => $other->id,
    ])->assertRedirect();

    expect($other->fresh()->computer_name)->toBe('DISPATCH-DESK')
        ->and(UserDevice::query()->where('computer_name', 'HIJACKED')->count())->toBe(1);
});
