<?php

/*
 * The moderation screens. The gate itself is proved in ApprovalGateTest; this covers what the two screens are
 * handed — the queue with the terminal each signup came from, and the approval columns on the user list.
 */

use App\Models\User;
use App\Modules\Activity\Models\UserDevice;
use App\Modules\Activity\Services\ActionLogger;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Access\Approval;
use App\Modules\Foundation\Access\Permissions;
use Carbon\CarbonImmutable;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->admin = User::factory()->create()->assignRole(Permissions::ROLE_ADMIN);
});

it('shows the queue oldest first, with the terminal and address each signup came from', function () {
    $device = UserDevice::factory()->create(['computer_name' => 'PPC-OFFICE-01']);
    $older = User::factory()->pendingApproval()->create(['name' => 'Older Signup']);
    $older->forceFill(['created_at' => CarbonImmutable::now()->subDays(3)])->save();
    $newer = User::factory()->pendingApproval()->create(['name' => 'Newer Signup']);

    app(ActionLogger::class)->record(
        ActionKeys::USER_REGISTERED, $older, ['email' => $older->email], $older,
        $device, '10.20.30.44', 'Mozilla/5.0', CarbonImmutable::now()->subDays(3),
    );

    $this->actingAs($this->admin)->get('/admin/users/approvals')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Approvals')
            ->has('pending', 2)
            ->where('pending.0.id', $older->id)
            ->where('pending.0.computer_name', 'PPC-OFFICE-01')
            ->where('pending.0.ip_address', '10.20.30.44')
            ->where('pending.0.email_verified', true)
            ->where('pending.1.id', $newer->id)
            // Nothing was logged for the second signup, so there is nothing to claim about its terminal.
            ->where('pending.1.computer_name', null)
            ->where('pending.1.ip_address', null)
        );
});

it('drops an account out of the queue once it is decided', function () {
    $pending = User::factory()->pendingApproval()->create();

    $this->actingAs($this->admin)->post("/admin/users/{$pending->id}/approve")->assertRedirect();

    $this->actingAs($this->admin)->get('/admin/users/approvals')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('pending', 0));
});

it('keeps a rejected account out of the queue and carries its reason to the user list', function () {
    $pending = User::factory()->pendingApproval()->create();

    $this->actingAs($this->admin)
        ->post("/admin/users/{$pending->id}/reject", ['rejected_reason' => 'Left the company'])
        ->assertRedirect();

    $this->actingAs($this->admin)->get('/admin/users/approvals')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('pending', 0));

    $this->actingAs($this->admin)->get('/admin/users')->assertOk()
        ->assertInertia(function (Assert $page) use ($pending) {
            $row = collect($page->toArray()['props']['users'])->firstWhere('id', $pending->id);

            expect($row['approval_status'])->toBe(Approval::REJECTED)
                ->and($row['rejected_reason'])->toBe('Left the company')
                ->and($row['approved_by'])->toBe($this->admin->name)
                ->and($row['approved_at'])->not->toBeNull()
                ->and($row)->toHaveKeys(['email_verified', 'registered_at']);
        });
});

it('refuses the queue screen to a user who may not moderate', function () {
    $viewer = User::factory()->create()->assignRole(Permissions::ROLE_VIEWER);

    $this->actingAs($viewer)->get('/admin/users/approvals')->assertForbidden();
});

it('lets a manager moderate without holding admin.users.manage', function () {
    $manager = User::factory()->create()->assignRole(Permissions::ROLE_MANAGER);
    $pending = User::factory()->pendingApproval()->create();

    $this->actingAs($manager)->get('/admin/users/approvals')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/Approvals'));

    // The user list is a different permission; the queue screen must stand on its own.
    $this->actingAs($manager)->get('/admin/users')->assertForbidden();

    $this->actingAs($manager)->post("/admin/users/{$pending->id}/approve")->assertRedirect();

    expect($pending->fresh()->approval_status)->toBe(Approval::APPROVED);
});
