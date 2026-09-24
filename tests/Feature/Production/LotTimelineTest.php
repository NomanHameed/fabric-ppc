<?php

use App\Models\User;
use App\Modules\Activity\Services\ActionLogger;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\ColourOverride;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\IssueService;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotIntakeService;
use App\Modules\Production\Services\LotStateService;
use App\Modules\Production\Services\LotTimelineService;
use App\Modules\Production\Timeline\TimelineEvent;
use App\Modules\Production\Timeline\TimelineEventType;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole(Permissions::ROLE_PPC);
    // Back-dated so the import movement (moved_at = the lot's created_at) really is the first event.
    $this->lot = Lot::factory()->create(['greige_qty' => 1000, 'po_type' => 'DYED', 'created_at' => now()->subDays(10)]);
    app(LotIntakeService::class)->receive($this->lot, $this->ppc->id);
    $this->lot->refresh();
});

function timelineTypes(Lot $lot): array
{
    return app(LotTimelineService::class)->for($lot)->map(fn (TimelineEvent $e) => $e->type)->all();
}

it('starts with the import movement as "loaded"', function () {
    $events = app(LotTimelineService::class)->for($this->lot);

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(TimelineEventType::LOADED)
        ->and($events[0]->qty)->toBe(1000.0)
        ->and($events[0]->to)->toBe(__('production.positions.stock'));
});

it('names the route step a lot moved to, not the raw position', function () {
    app(IssueService::class)->issue($this->lot, 1000, $this->ppc);
    $step = $this->lot->routeSteps()->firstOrFail();
    app(LedgerService::class)->post($this->lot, 'process', Position::issued(), Position::step($step->id), 1000, 1000, ['userId' => $this->ppc->id]);

    $moved = app(LotTimelineService::class)->for($this->lot)->firstWhere('type', TimelineEventType::MOVED);

    expect($moved)->not->toBeNull()
        ->and($moved->from)->toBe(__('production.positions.issued'))
        ->and($moved->to)->toBe($step->operation->name)
        ->and($moved->actorName)->toBe($this->ppc->name)
        ->and($moved->title())->toContain($step->operation->name);
});

it('is ordered by time and covers issue, loss, finish and dispatch', function () {
    $ledger = app(LedgerService::class);
    app(IssueService::class)->issue($this->lot, 1000, $this->ppc, now()->subDays(5));
    $step = $this->lot->routeSteps()->firstOrFail();
    $ledger->post($this->lot, 'process', Position::issued(), Position::step($step->id), 1000, 1000, ['userId' => $this->ppc->id, 'movedAt' => now()->subDays(4)]);
    $ledger->post($this->lot, 'loss', Position::step($step->id), Position::loss(), 50, 50, ['userId' => $this->ppc->id, 'movedAt' => now()->subDays(3), 'reason' => 'SHRINKAGE']);
    $ledger->post($this->lot, 'process', Position::step($step->id), Position::finished(), 950, 950, ['userId' => $this->ppc->id, 'movedAt' => now()->subDays(2)]);
    $ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 950, 950, ['userId' => $this->ppc->id, 'movedAt' => now()->subDay()]);

    expect(timelineTypes($this->lot))->toBe([
        TimelineEventType::LOADED,
        TimelineEventType::ISSUED,
        TimelineEventType::MOVED,
        TimelineEventType::LOSS,
        TimelineEventType::MOVED,
        TimelineEventType::DISPATCHED,
    ]);
});

it('shows a reversal as its own entry rather than rewriting history', function () {
    $ledger = app(LedgerService::class);
    $issue = app(IssueService::class)->issue($this->lot, 400, $this->ppc);
    $ledger->reverse($issue, $this->ppc->id, 'Issued against the wrong lot');

    expect(timelineTypes($this->lot))->toBe([
        TimelineEventType::LOADED, TimelineEventType::ISSUED, TimelineEventType::REVERSED,
    ]);
});

it('picks up state changes from the audit trail with the actor who made them', function () {
    $manager = User::factory()->create()->assignRole(Permissions::ROLE_MANAGER);

    config(['audit.console' => true]);
    ImportCommitService::asAuditUser($manager, fn () => app(LotStateService::class)->hold($this->lot, 'PARTY_HOLD', $manager));

    $held = app(LotTimelineService::class)->for($this->lot)->firstWhere('type', TimelineEventType::STATE_CHANGED);

    expect($held)->not->toBeNull()
        ->and($held->from)->toBe('open')
        ->and($held->to)->toBe('hold')
        ->and($held->note)->toBe('PARTY_HOLD')
        ->and($held->actorName)->toBe($manager->name);
});

it('picks up colour overrides and recorded user actions', function () {
    $lab = User::factory()->create()->assignRole(Permissions::ROLE_COLOUR_LAB);

    ColourOverride::query()->create([
        'lot_id' => $this->lot->id, 'reason' => 'Party confirmed by phone',
        'approved_by' => $lab->id, 'approved_at' => now(),
    ]);
    app(ActionLogger::class)->log(ActionKeys::COLOUR_APPROVED, $this->lot, ['colour' => 'NAVY BLUE', 'lot' => $this->lot->lot_no], $lab);

    $types = timelineTypes($this->lot);

    expect($types)->toContain(TimelineEventType::COLOUR_OVERRIDE_APPROVED)
        ->and($types)->toContain(TimelineEventType::ACTION);

    $action = app(LotTimelineService::class)->for($this->lot)->firstWhere('type', TimelineEventType::ACTION);
    expect($action->title())->toContain('NAVY BLUE')->and($action->actorName)->toBe($lab->name);
});

it('filters by type and honours a limit', function () {
    app(IssueService::class)->issue($this->lot, 1000, $this->ppc);

    expect(app(LotTimelineService::class)->for($this->lot, [TimelineEventType::ISSUED]))->toHaveCount(1)
        ->and(app(LotTimelineService::class)->for($this->lot, [], 1))->toHaveCount(1);
});

it('serves the timeline over http to anyone with inquiry.view', function () {
    app(IssueService::class)->issue($this->lot, 1000, $this->ppc);

    $this->actingAs($this->ppc)->getJson("/production/lots/{$this->lot->id}/timeline")
        ->assertOk()
        ->assertJsonPath('lot.lot_no', $this->lot->lot_no)
        ->assertJsonPath('events.0.type', TimelineEventType::LOADED)
        ->assertJsonPath('events.1.type', TimelineEventType::ISSUED)
        ->assertJsonStructure(['events' => [['id', 'type', 'title', 'occurred_at', 'from', 'to', 'qty', 'actor', 'note']]]);
});

it('stays in step with the ledger: nothing is stored, so nothing can drift', function () {
    app(IssueService::class)->issue($this->lot, 1000, $this->ppc);
    $before = count(timelineTypes($this->lot));

    app(LedgerService::class)->rebuild($this->lot);

    expect(timelineTypes($this->lot))->toHaveCount($before);
    app(LedgerService::class)->assertInvariant($this->lot);
});
