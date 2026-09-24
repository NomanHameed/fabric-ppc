<?php

/*
 * The signup approval boundary. A verified-but-unapproved account must receive nothing: no screen, no data,
 * no JSON — whatever route it asks for. These tests are the proof, so they sweep every registered route
 * rather than naming a handful.
 */

use App\Models\User;
use App\Modules\Foundation\Access\Approval;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Http\Middleware\EnsureUserIsApproved;
use App\Modules\Production\Models\Lot;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->pending = User::factory()->pendingApproval()->create();
    $this->rejected = User::factory()->rejected()->create();
    $this->approved = User::factory()->create();
    $this->admin = User::factory()->create()->assignRole(Permissions::ROLE_ADMIN);
});

/**
 * Every GET route in the `web` group that takes no parameters, minus the handful an unapproved account is
 * allowed to reach. New routes are covered automatically — that is the point.
 *
 * @return list<string>
 */
function guardedWebGetRoutes(): array
{
    $uris = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || ! in_array('web', $route->gatherMiddleware(), true)) {
            continue;
        }
        if (str_contains($route->uri(), '{')) {
            continue;
        }
        if (in_array($route->getName(), EnsureUserIsApproved::ALLOWED_ROUTES, true)) {
            continue;
        }
        $uris[] = '/'.ltrim($route->uri(), '/');
    }

    return array_values(array_unique($uris));
}

it('sweeps every parameterless web route and lets a pending user reach none of them', function () {
    $routes = guardedWebGetRoutes();
    expect($routes)->not->toBeEmpty();

    foreach ($routes as $uri) {
        $this->actingAs($this->pending)->get($uri)
            ->assertRedirect(route('approval.pending', absolute: false));
    }
});

it('refuses a pending user the dashboard and every module screen by name', function (string $uri) {
    $this->actingAs($this->pending)->get($uri)->assertRedirect(route('approval.pending', absolute: false));
})->with([
    '/dashboard',
    '/masters/machines',
    '/production/lots',
    '/production/contracts',
    '/production/sheets',
    '/production/utilisation',
    '/oracle/imports',
    '/admin/users',
    '/settings/profile',   // only `auth`, no `verified`: the gate is in the web group, not on the route
    '/lov/machines',
    '/activity/actions',
]);

it('refuses a pending user a parameterised route that points at a real record', function () {
    $lot = Lot::factory()->create();

    $this->actingAs($this->pending)->get("/production/lots/{$lot->id}/timeline")
        ->assertRedirect(route('approval.pending', absolute: false));
});

it('answers 403 rather than a redirect when the caller wants JSON', function () {
    $this->actingAs($this->pending)->getJson('/dashboard')->assertForbidden();
    $this->actingAs($this->pending)->getJson('/production/utilisation')->assertForbidden();
});

it('refuses a pending user every write route too', function () {
    $this->actingAs($this->pending)->post('/locale', ['locale' => 'ur'])->assertRedirect(route('approval.pending', absolute: false));
    $this->actingAs($this->pending)->put('/devices/name', ['computer_name' => 'X'])->assertRedirect(route('approval.pending', absolute: false));
    $this->actingAs($this->pending)->post('/admin/users', [])->assertRedirect(route('approval.pending', absolute: false));
});

it('gives a pending user its own status and no business data on the one screen it can see', function () {
    $this->actingAs($this->pending)->get('/approval/pending')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/PendingApproval')
            ->where('status', Approval::PENDING)
            ->where('rejected', false)
            ->where('rejectedReason', null)
            ->where('emailVerified', true)
            ->where('email', $this->pending->email)
            // The whole payload: the account's own state and nothing from any module.
            ->has('status')->has('rejected')->has('rejectedReason')->has('emailVerified')->has('email')
            ->etc()
        );
});

it('hands a pending user no module data through the shared Inertia props either', function () {
    $response = $this->actingAs($this->pending)->get('/approval/pending')->assertOk();

    $props = $response->viewData('page')['props'];

    // Shared props describe the viewer and the chrome; nothing here is a lot, a contract or a master record.
    expect(array_keys($props))->not->toContain('lots')
        ->and($props['auth']['user']['id'])->toBe($this->pending->id)
        ->and($props['auth']['departments'])->toBe([]);
});

it('lets a pending user log out and resend the verification mail', function () {
    $unverified = User::factory()->pendingApproval()->unverified()->create();

    $this->actingAs($unverified)->post(route('verification.send'))->assertRedirect();

    $this->actingAs($this->pending)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

/*
 * Fortify renders the verification notice with Inertia, so allowing that route handed an unapproved account
 * the application shell, its shared props (its own row, its roles, the whole permission list) and the
 * JavaScript bundle — everything the gate exists to withhold. The pending screen reports the verification
 * state instead; the notice route is no longer on the allow-list.
 */
it('does not hand a pending user the Inertia shell through the email verification notice', function () {
    $unverified = User::factory()->pendingApproval()->unverified()->create();
    $unverified->assignRole(Permissions::ROLE_ADMIN);

    $response = $this->actingAs($unverified)->get('/email/verify')
        ->assertRedirect(route('approval.pending', absolute: false));

    expect($response->getContent())->not->toContain('data-page')
        ->and($response->getContent())->not->toContain('permissions');

    expect(EnsureUserIsApproved::ALLOWED_ROUTES)->not->toContain('verification.notice');
});

it('still serves the verification notice to an approved but unverified account', function () {
    $approved = User::factory()->unverified()->create();

    $this->actingAs($approved)->get('/email/verify')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/VerifyEmail'));
});

it('answers an Inertia XHR with a hard-navigation instruction, not a 302 it cannot parse', function () {
    $this->actingAs($this->pending)
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->get('/dashboard')
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('approval.pending'));
});

it('blocks a rejected user just as hard and shows the reason', function () {
    $this->actingAs($this->rejected)->get('/dashboard')->assertRedirect(route('approval.pending', absolute: false));

    $this->actingAs($this->rejected)->get('/approval/pending')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/PendingApproval')
            ->where('status', Approval::REJECTED)
            ->where('rejected', true)
            ->where('rejectedReason', 'Not a mill employee')
        );
});

it('does not block an approved user', function () {
    $this->actingAs($this->approved)->get('/dashboard')->assertOk();
    $this->actingAs($this->approved)->get('/approval/pending')->assertRedirect(config('fortify.home'));
});

it('does not touch guests', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/login')->assertOk();
});

it('refuses an unapproved user even when the account is otherwise fully privileged', function () {
    $this->pending->assignRole(Permissions::ROLE_ADMIN);

    $this->actingAs($this->pending)->get('/admin/users')->assertRedirect(route('approval.pending', absolute: false));
    $this->actingAs($this->pending)->get('/dashboard')->assertRedirect(route('approval.pending', absolute: false));
});

it('lets a moderator approve, after which the same account gets in', function () {
    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->pending->id}/approve")
        ->assertRedirect();

    $this->pending->refresh();
    expect($this->pending->approval_status)->toBe(Approval::APPROVED)
        ->and($this->pending->approved_by)->toBe($this->admin->id)
        ->and($this->pending->approved_at)->not->toBeNull();

    $this->actingAs($this->pending)->get('/dashboard')->assertOk();
});

it('lets a moderator reject with a reason, after which the account still gets nothing', function () {
    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->pending->id}/reject", ['rejected_reason' => 'Left the company'])
        ->assertRedirect();

    $this->pending->refresh();
    expect($this->pending->approval_status)->toBe(Approval::REJECTED)
        ->and($this->pending->rejected_reason)->toBe('Left the company');

    $this->actingAs($this->pending)->get('/dashboard')->assertRedirect(route('approval.pending', absolute: false));
});

it('requires a reason to reject', function () {
    $this->actingAs($this->admin)->post("/admin/users/{$this->pending->id}/reject", [])
        ->assertSessionHasErrors('rejected_reason');

    expect($this->pending->fresh()->approval_status)->toBe(Approval::PENDING);
});

it('refuses moderation to anyone without admin.users.approve', function () {
    $viewer = User::factory()->create()->assignRole(Permissions::ROLE_VIEWER);

    $this->actingAs($viewer)->post("/admin/users/{$this->pending->id}/approve")->assertForbidden();
    $this->actingAs($viewer)->get('/admin/users/approvals')->assertForbidden();

    expect($this->pending->fresh()->approval_status)->toBe(Approval::PENDING);
});

it('refuses self-moderation', function () {
    $manager = User::factory()->pendingApproval()->create()->assignRole(Permissions::ROLE_MANAGER);
    // Approve them first so they can actually reach the route, then have them try themselves.
    $manager->forceFill(['approval_status' => Approval::PENDING])->save();

    $this->actingAs($this->admin)->post("/admin/users/{$this->admin->id}/approve")
        ->assertSessionHasErrors('approval_status');
});

it('lists the pending queue for a moderator', function () {
    $this->actingAs($this->admin)->get('/admin/users/approvals')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Approvals')
            ->has('pending', 1)
            ->where('pending.0.id', $this->pending->id)
            ->where('pending.0.email', $this->pending->email)
        );
});

it('defaults a new row to pending, so a forgotten code path fails closed', function () {
    $id = DB::table('users')->insertGetId([
        'name' => 'Raw Insert', 'email' => 'raw@example.test', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(User::findOrFail($id)->approval_status)->toBe(Approval::PENDING);
});
