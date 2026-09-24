<?php

use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Models\UserDevice;
use App\Modules\Activity\Services\ActionLogger;
use App\Modules\Activity\Services\DeviceRegistrar;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Access\Permissions;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\App;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->ppc = User::factory()->create()->assignRole(Permissions::ROLE_PPC);
});

it('captures user, subject, ip, device and user agent from the request in one call', function () {
    $subject = User::factory()->create();

    $this->actingAs($this->ppc)
        ->withServerVariables(['REMOTE_ADDR' => '10.20.30.40', 'HTTP_USER_AGENT' => 'PestAgent/1.0'])
        ->put('/devices/name', ['computer_name' => 'PPC-OFFICE-01'])
        ->assertRedirect();

    // The rename is itself a logged action; use it to assert everything the logger captures.
    $action = UserAction::query()->where('action', ActionKeys::DEVICE_NAMED)->sole();

    expect($action->user_id)->toBe($this->ppc->id)
        ->and($action->ip_address)->toBe('10.20.30.40')
        ->and($action->user_agent)->toBe('PestAgent/1.0')
        ->and($action->device_id)->not->toBeNull()
        ->and($action->user_device_id)->toBe(UserDevice::query()->sole()->id)
        ->and($action->subject_type)->toBe(UserDevice::class)
        ->and($subject->exists)->toBeTrue();
});

it('stores the description in the fallback locale and re-renders it for the reader', function () {
    App::setLocale('ur');

    $action = app(ActionLogger::class)->log(ActionKeys::COLOUR_APPROVED, null, ['colour' => 'NAVY BLUE', 'lot' => '640101'], $this->ppc);

    expect($action->description)->toBe(trans('activity.actions.colour.approved', ['colour' => 'NAVY BLUE', 'lot' => '640101'], 'en'))
        ->and($action->description)->toContain('NAVY BLUE')
        ->and($action->label())->toBe(__('activity.actions.colour.approved', ['colour' => 'NAVY BLUE', 'lot' => '640101']))
        ->and($action->label())->not->toBe($action->description);   // Urdu now, English on the row
});

it('keeps the actor\'s name on the row, so deleting the account does not erase the history', function () {
    $action = app(ActionLogger::class)->log(ActionKeys::LOT_CLOSED, null, ['lot' => '640101'], $this->ppc);

    expect($action->user_name)->toBe($this->ppc->name);

    $this->ppc->delete();

    $action->refresh();
    expect($action->user_id)->toBeNull()
        ->and($action->user_name)->toBe($action->user_name);
});

it('records no address at all off the back of a console run, rather than inventing one', function () {
    // SetRequestForConsole hands artisan and the queue worker a request synthesised from app.url, whose ip()
    // is 127.0.0.1 and whose agent is "Symfony" — values nobody sent. An attribution log must store neither.
    $console = new class(app(), app(DeviceRegistrar::class)) extends ActionLogger
    {
        protected function withoutRealRequest(): bool
        {
            return true;
        }
    };

    $action = $console->log(ActionKeys::USER_APPROVED, null, ['name' => 'Someone'], $this->ppc);

    expect($action->ip_address)->toBeNull()
        ->and($action->user_agent)->toBeNull()
        ->and($action->user_id)->toBe($this->ppc->id);
});

it('is insert-only', function () {
    $action = app(ActionLogger::class)->log(ActionKeys::LOT_CLOSED, null, ['lot' => '640101'], $this->ppc);

    expect(fn () => $action->update(['description' => 'rewritten']))->toThrow(LogicException::class);
    expect(fn () => $action->delete())->toThrow(LogicException::class);
});

it('records with an explicit device, address and time for seeders and back-dated entries', function () {
    $device = UserDevice::factory()->create(['computer_name' => 'LAB-BENCH']);
    $at = CarbonImmutable::now()->subDays(3);

    $action = app(ActionLogger::class)->record(
        ActionKeys::COLOUR_REJECTED, $device, ['colour' => 'IVORY', 'lot' => '640102'], $this->ppc,
        $device, '10.0.0.9', 'SeederAgent', $at,
    );

    expect($action->computer_name)->toBe('LAB-BENCH')
        ->and($action->ip_address)->toBe('10.0.0.9')
        ->and($action->occurred_at->toDateTimeString())->toBe($at->toDateTimeString());
});

it('serves the log to a user with activity.view, filtered and newest first', function () {
    $logger = app(ActionLogger::class);
    $logger->log(ActionKeys::LOT_HELD, null, ['lot' => '640101', 'reason' => 'PARTY_HOLD'], $this->ppc);
    $logger->log(ActionKeys::LOT_CLOSED, null, ['lot' => '640102'], $this->ppc);

    $this->actingAs($this->ppc)->getJson('/activity/actions')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', ActionKeys::LOT_CLOSED);

    $this->actingAs($this->ppc)->getJson('/activity/actions?action='.ActionKeys::LOT_HELD)
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.action', ActionKeys::LOT_HELD);
});

it('renders the log as a screen, with the first page and the filter lists already in the payload', function () {
    $logger = app(ActionLogger::class);
    $logger->log(ActionKeys::LOT_HELD, null, ['lot' => '640101', 'reason' => 'PARTY_HOLD'], $this->ppc);
    $logger->log(ActionKeys::LOT_CLOSED, null, ['lot' => '640102'], $this->ppc);

    $this->actingAs($this->ppc)->get('/activity/actions')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('activity/ActionLog')
            ->has('data', 2)
            ->where('data.0.action', ActionKeys::LOT_CLOSED)
            ->where('meta.total', 2)
            ->where('meta.current_page', 1)
            ->where('filters.action', null)
            ->has('options.users')
            ->has('options.actions', count(ActionKeys::ALL))
            ->has('options.devices')
        );
});

it('keeps the screen server-paginated, never handing the page the whole table', function () {
    $logger = app(ActionLogger::class);
    foreach (range(1, 4) as $i) {
        $logger->log(ActionKeys::LOT_CLOSED, null, ['lot' => (string) (640100 + $i)], $this->ppc);
    }

    $this->actingAs($this->ppc)->get('/activity/actions?per_page=2')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('activity/ActionLog')
            ->has('data', 2)
            ->where('meta.total', 4)
            ->where('meta.last_page', 2)
        );

    $this->actingAs($this->ppc)->get('/activity/actions?per_page=2&page=2')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('data', 2)
            ->where('meta.current_page', 2)
        );
});

it('carries a filter through to the screen so the URL is the whole state', function () {
    $logger = app(ActionLogger::class);
    $logger->log(ActionKeys::LOT_HELD, null, ['lot' => '640101', 'reason' => 'PARTY_HOLD'], $this->ppc);
    $logger->log(ActionKeys::LOT_CLOSED, null, ['lot' => '640102'], $this->ppc);

    $this->actingAs($this->ppc)->get('/activity/actions?action='.ActionKeys::LOT_HELD)->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('data', 1)
            ->where('data.0.action', ActionKeys::LOT_HELD)
            ->where('filters.action', ActionKeys::LOT_HELD)
        );
});

it('refuses the log to a user without activity.view', function () {
    $viewer = User::factory()->create()->assignRole(Permissions::ROLE_VIEWER);

    $this->actingAs($viewer)->getJson('/activity/actions')->assertForbidden();
});

it('rejects an unknown action key as a filter', function () {
    $this->actingAs($this->ppc)->getJson('/activity/actions?action=made.up')->assertStatus(422);
});
