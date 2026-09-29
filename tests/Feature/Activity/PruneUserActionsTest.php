<?php

/*
 * `user_actions` is insert-only by design — the model throws on update and on delete, so no request, no
 * observer and no stray `model:prune` can rewrite history. That guard also left the table with no way to stop
 * growing, on a log that gains a row per signup, approval, issue, sheet line, colour decision and capacity
 * allocation. `activity:prune` is the one sanctioned exit, through the query builder, on the indexed
 * `occurred_at` column.
 */

use App\Models\User;
use App\Modules\Activity\Console\PruneUserActions;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Services\ActionLogger;
use App\Modules\Activity\Support\ActionKeys;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->logAt = function (CarbonImmutable $at, string $lot): UserAction {
        return app(ActionLogger::class)->record(
            ActionKeys::LOT_CLOSED, null, ['lot' => $lot], $this->user, null, null, null, $at,
        );
    };
});

it('deletes rows older than the window and keeps newer ones', function () {
    ($this->logAt)(CarbonImmutable::now()->subDays(400), 'OLD');
    ($this->logAt)(CarbonImmutable::now()->subDays(10), 'NEW');

    $this->artisan('activity:prune', ['--days' => 30])->assertSuccessful();

    expect(UserAction::query()->count())->toBe(1)
        ->and(UserAction::query()->sole()->description)->toContain('NEW');
});

it('keeps two years by default', function () {
    ($this->logAt)(CarbonImmutable::now()->subDays(PruneUserActions::DEFAULT_DAYS + 5), 'ANCIENT');
    ($this->logAt)(CarbonImmutable::now()->subDays(PruneUserActions::DEFAULT_DAYS - 5), 'RECENT');

    $this->artisan('activity:prune')->assertSuccessful();

    expect(UserAction::query()->count())->toBe(1)
        ->and(UserAction::query()->sole()->description)->toContain('RECENT');
});

it('deletes nothing when asked to pretend', function () {
    ($this->logAt)(CarbonImmutable::now()->subDays(400), 'OLD');

    $this->artisan('activity:prune', ['--days' => 30, '--pretend' => true])->assertSuccessful();

    expect(UserAction::query()->count())->toBe(1);
});

it('refuses a window that would empty the whole log', function () {
    ($this->logAt)(CarbonImmutable::now()->subDays(400), 'OLD');

    $this->artisan('activity:prune', ['--days' => 0])->assertFailed();

    expect(UserAction::query()->count())->toBe(1);
});

it('works past a backlog larger than one chunk', function () {
    $rows = [];
    $at = CarbonImmutable::now()->subDays(400);
    for ($i = 0; $i < PruneUserActions::CHUNK + 25; $i++) {
        $rows[] = [
            'user_id' => $this->user->id, 'user_name' => $this->user->name, 'action' => ActionKeys::LOT_CLOSED,
            'description' => 'Closed lot '.$i, 'occurred_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        UserAction::query()->insert($chunk);
    }

    $this->artisan('activity:prune', ['--days' => 30])->assertSuccessful();

    expect(UserAction::query()->count())->toBe(0);
});

it('leaves the model guard in place: the log is still insert-only', function () {
    $action = ($this->logAt)(CarbonImmutable::now(), 'GUARDED');

    expect(fn () => $action->delete())->toThrow(LogicException::class)
        ->and(fn () => $action->update(['description' => 'edited']))->toThrow(LogicException::class)
        ->and(UserAction::query()->count())->toBe(1);
});
