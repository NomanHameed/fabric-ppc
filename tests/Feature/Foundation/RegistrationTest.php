<?php

/*
 * Self-registration is open (the project previously had it disabled; the owner reversed that). What it must
 * never do is let anybody in: a new account is `pending` and sees nothing until a moderator approves it.
 */

use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Access\Approval;
use App\Modules\Foundation\Access\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

it('exposes the registration screen', function () {
    $this->get('/register')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Register')
            ->has('passwordRules')
        );
});

it('creates a pending account and sends the verification mail', function () {
    Notification::fake();

    $this->post('/register', [
        'name' => 'New Operator',
        'email' => 'new.operator@example.test',
        'password' => 'secret-password-1',
        'password_confirmation' => 'secret-password-1',
    ])->assertRedirect();

    $user = User::query()->where('email', 'new.operator@example.test')->firstOrFail();

    expect($user->approval_status)->toBe(Approval::PENDING)
        ->and($user->approved_by)->toBeNull()
        ->and($user->approved_at)->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->is_active)->toBeTrue();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('records the signup in the action log', function () {
    $this->post('/register', [
        'name' => 'Logged Signup', 'email' => 'logged@example.test',
        'password' => 'secret-password-1', 'password_confirmation' => 'secret-password-1',
    ]);

    $user = User::query()->where('email', 'logged@example.test')->firstOrFail();
    $action = UserAction::query()->forSubject($user)->firstOrFail();

    expect($action->action)->toBe(ActionKeys::USER_REGISTERED)
        ->and($action->user_id)->toBe($user->id)
        ->and($action->description)->toContain('logged@example.test')
        ->and($action->device_id)->not->toBeNull();   // TrackDevice ran before the request reached Fortify
});

it('sends the freshly registered user to the pending screen, not the dashboard', function () {
    $this->post('/register', [
        'name' => 'Straight To Pending', 'email' => 'straight@example.test',
        'password' => 'secret-password-1', 'password_confirmation' => 'secret-password-1',
    ]);

    $this->get('/dashboard')->assertRedirect(route('approval.pending', absolute: false));
});

it('cannot be talked into approving itself through the registration payload', function () {
    $this->post('/register', [
        'name' => 'Sneaky', 'email' => 'sneaky@example.test',
        'password' => 'secret-password-1', 'password_confirmation' => 'secret-password-1',
        'approval_status' => Approval::APPROVED, 'approved_at' => now()->toDateTimeString(), 'approved_by' => 1,
        'is_active' => true,
    ]);

    $user = User::query()->where('email', 'sneaky@example.test')->firstOrFail();

    expect($user->approval_status)->toBe(Approval::PENDING)
        ->and($user->approved_by)->toBeNull();
});

it('approves accounts an administrator creates, because that is the decision', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create()->assignRole(Permissions::ROLE_ADMIN);

    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Made By Admin', 'email' => 'made@example.test', 'password' => 'secret-pass-1',
        'is_active' => true, 'locale' => 'en', 'roles' => ['viewer'], 'department_ids' => [],
    ])->assertRedirect();

    $user = User::query()->where('email', 'made@example.test')->firstOrFail();

    expect($user->approval_status)->toBe(Approval::APPROVED)
        ->and($user->approved_by)->toBe($admin->id);
});
