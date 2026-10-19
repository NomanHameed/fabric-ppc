<?php

use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use Database\Seeders\StageSeeder;

/*
 * Spec §17/§19: the two stage masters are drawn by the generic Oracle-Forms master screen, which builds
 * itself from the resource's field definitions. Feature tests prove the controller and the resource; only a
 * browser proves a field actually reaches the screen, binds the stored value and sends it back.
 *
 * Two different things are proven here, because the two tables are now different kinds of thing:
 *
 *  - `stages.status_aliases` is the one place the status -> stage mapping lives, edited as a single
 *    comma-separated line.
 *  - `stage_processes` was **deliberately restructured by the user** (migration `2026_10_13_000100`): it is
 *    no longer the process-name taxonomy (`code`, `name`, `sort_order`, `quantity_type`, `is_wip`,
 *    `is_finished`, `is_dispatch`, `status_aliases`, `is_active` are all dropped) but one Oracle production
 *    line per row — the twenty fields the mill works from, linked to its reporting stage. The screen is
 *    therefore a stage select plus twenty Oracle columns, and that is what is asserted below.
 *
 * Both masters are gated on `masters.view` to see and `masters.manage` to edit, and the PPC role
 * (`browserLogin()`'s default) holds both — so no extra permission is granted anywhere to make these pass.
 *
 * Every field is addressed by an **explicit** selector (`[id="f-po_no"]`, not `f-po_no`). Pest's browser
 * plugin guesses what a selector means (Pest\Browser\Support\Selector::isExplicit): a bare word carries no
 * CSS punctuation, so it is first tried as `[id=…]`/`[name=…]` and, if that matches *nothing at that
 * instant*, silently re-read as page **text** — which matches nothing either, and the action that follows
 * then waits for it with no timeout and hangs forever instead of failing. Reading a field straight after a
 * save, while Inertia is swapping the record in, is exactly that race.
 */

/**
 * The twenty Oracle columns the Stage Processes screen edits, in the order `StageProcessResource` lists them.
 *
 * Spelled out rather than read from the model: the point of the list is that the *screen* carries exactly
 * these columns, so a column silently added to or dropped from the resource should make a test fail, not
 * quietly change what is being checked.
 *
 * @return list<string>
 */
function stageProcessOracleColumns(): array
{
    return [
        'lot_no_oracle', 'po_no', 'ref', 'date_oracle',
        'party_oracle_id', 'party_oracle_name', 'quality',
        'design_oracle_id', 'color_oracle_id', 'color_oracle_name', 'clr_status_oracle', 'po_type_oracle',
        'status_oracle', 'gw_oracle',
        'greige_received_oracle', 'greige_in_stock_oracle', 'qty_issued', 'wip', 'finished', 'dispatch',
    ];
}

it('shows a stage\'s status aliases as one comma-separated line and round-trips a new list', function () {
    browserLogin();                      // ppc: masters.view + masters.manage
    test()->seed(StageSeeder::class);    // the real taxonomy; Greige claims #BLANK and sorts first

    $greige = Stage::query()->where('code', 'GREIGE')->firstOrFail();

    $page = visit('/masters/stages');

    // The field is on the screen, labelled from the masters lang group, showing what the stage claims.
    $page->assertSee(__('masters.titles.stages'))
        ->assertSee(__('masters.fields.status_aliases'))
        ->assertValue('[id="f-code"]', 'GREIGE')
        ->assertValue('[id="f-status_aliases"]', Stage::BLANK_ALIAS)
        ->assertNoJavaScriptErrors();

    // Typed deliberately messily: a trailing space, an empty entry and a repeat. What comes back is the
    // list the *server* parsed and re-joined, not the string the browser still had in the box.
    $page->fill('[id="f-status_aliases"]', '#BLANK , GREIGE STOCK,, STOCK * , #BLANK')
        ->click('[aria-label="'.__('forms.actions.save').'"]')
        ->assertSee(__('forms.saved'))
        ->assertValue('[id="f-status_aliases"]', '#BLANK, GREIGE STOCK, STOCK *')
        ->assertNoJavaScriptErrors();

    // …and the JSON column itself, so a screen that merely looks right cannot pass.
    expect($greige->fresh()?->status_aliases)->toBe(['#BLANK', 'GREIGE STOCK', 'STOCK *']);
});

it('renders every Oracle column of a stage process and saves what is typed into them', function () {
    browserLogin();

    // Two stages, so the row can be moved from one to the other: the link to the stage is the only
    // structural column `stage_processes` has left, and it is a select built from the stages table.
    $dyeing = Stage::factory()->create(['code' => 'DYEING', 'name' => 'Dyeing', 'sort_order' => 30]);
    $finishing = Stage::factory()->create(['code' => 'FINISHING', 'name' => 'Finishing', 'sort_order' => 50]);

    $process = StageProcess::factory()->create([
        'stage_id' => $dyeing->getKey(),
        'party_oracle_id' => 4021, 'party_oracle_name' => 'Al-Karam Textile',
        'po_no' => 778899, 'ref' => 'REF-7788',
        'design_oracle_id' => 'D-114', 'color_oracle_id' => 'C-92', 'color_oracle_name' => 'Navy Blue',
        'clr_status_oracle' => 'APPROVED', 'po_type_oracle' => 'DYED',
        'date_oracle' => '2026-09-18', 'lot_no_oracle' => '117', 'quality' => '20x20 / 60x60 VISCOSE',
        'gw_oracle' => '63.50', 'status_oracle' => 'DYEING/',
        'greige_received_oracle' => '1200.50', 'greige_in_stock_oracle' => '200.25',
        'qty_issued' => '1000.25', 'wip' => '600.00', 'finished' => '350.75', 'dispatch' => '49.50',
    ]);

    $page = visit('/masters/stage_processes');

    // The stage select, bound to the stage the row is filed under.
    $page->assertSee(__('masters.titles.stage_processes'))
        ->assertSee(__('masters.fields.stage'))
        ->assertSelected('[id="f-stage_id"]', (string) $dyeing->getKey())
        ->assertNoJavaScriptErrors();

    // Every one of the twenty Oracle columns reaches the screen, with its label from `masters.fields.*`.
    foreach (stageProcessOracleColumns() as $column) {
        $page->assertSee(__('masters.fields.'.$column))
            ->assertPresent('[id="f-'.$column.'"]');
    }

    // A sample across the types — text, integer, the date the resource formats back to `d-m-Y` (the mill's
    // one date format), and two decimals — bound to what the row actually holds.
    $page->assertValue('[id="f-lot_no_oracle"]', '117')
        ->assertValue('[id="f-po_no"]', '778899')
        ->assertValue('[id="f-party_oracle_id"]', '4021')
        ->assertValue('[id="f-party_oracle_name"]', 'Al-Karam Textile')
        ->assertValue('[id="f-date_oracle"]', '18-09-2026')
        ->assertValue('[id="f-quality"]', '20x20 / 60x60 VISCOSE')
        ->assertValue('[id="f-gw_oracle"]', '63.50')
        ->assertValue('[id="f-status_oracle"]', 'DYEING/')
        ->assertValue('[id="f-greige_received_oracle"]', '1200.50')
        ->assertValue('[id="f-wip"]', '600.00');

    // Retyped on the screen: moved to another stage, re-coloured, re-referenced, and a decimal quantity
    // that has to survive the round trip as metres and not as a rounded integer.
    $page->select('[id="f-stage_id"]', (string) $finishing->getKey())
        ->fill('[id="f-color_oracle_name"]', 'Teal')
        ->fill('[id="f-ref"]', 'REF-7799')
        ->fill('[id="f-status_oracle"]', 'FINISHING/')
        ->fill('[id="f-date_oracle"]', '20-09-2026')
        ->fill('[id="f-finished"]', '1200.50')
        ->click('[aria-label="'.__('forms.actions.save').'"]')
        ->assertSee(__('forms.saved'))
        ->assertSelected('[id="f-stage_id"]', (string) $finishing->getKey())
        ->assertValue('[id="f-finished"]', '1200.50')
        // the PO date goes out as `d-m-Y` and comes back as `d-m-Y`, not as ISO or a timestamp
        ->assertValue('[id="f-date_oracle"]', '20-09-2026')
        ->assertNoJavaScriptErrors();

    // The row in the database, not the screen: the link to the stage, what was retyped, and what was not.
    $saved = $process->fresh();
    expect($saved?->stage_id)->toBe((int) $finishing->getKey())
        ->and($saved?->stage?->code)->toBe('FINISHING')
        ->and($saved?->color_oracle_name)->toBe('Teal')
        ->and($saved?->ref)->toBe('REF-7799')
        ->and($saved?->status_oracle)->toBe('FINISHING/')
        // decimal(12,2), not int: 1200.50 metres stay 1200.50 (CLAUDE.md)
        ->and($saved?->finished)->toBe('1200.50')
        ->and($saved?->lot_no_oracle)->toBe('117')
        ->and($saved?->po_no)->toBe(778899)
        // retyped as `d-m-Y` and stored at midnight on that day
        ->and($saved?->date_oracle?->format('Y-m-d H:i:s'))->toBe('2026-09-20 00:00:00')
        ->and($saved?->gw_oracle)->toBe('63.50')
        ->and($saved?->wip)->toBe('600.00');
});

it('stores the columns left blank on a half-filled stage process as null, not zero', function () {
    browserLogin();

    $stage = Stage::factory()->create(['code' => 'GREIGE', 'name' => 'Greige', 'sort_order' => 10]);

    // `stage_processes` is empty after a seed — the migration deleted the eighteen taxonomy rows and
    // `StageSeeder` seeds stages only — so the screen opens on a blank record, which is exactly the case
    // being tested: a row entered by hand where only the stage, the lot number and one quantity are known.
    expect(StageProcess::query()->count())->toBe(0);

    visit('/masters/stage_processes')
        ->assertSee(__('masters.titles.stage_processes'))
        ->assertValue('[id="f-lot_no_oracle"]', '')
        ->select('[id="f-stage_id"]', (string) $stage->getKey())
        ->fill('[id="f-lot_no_oracle"]', '12')
        ->fill('[id="f-greige_received_oracle"]', '900.25')
        ->click('[aria-label="'.__('forms.actions.save').'"]')
        ->assertSee(__('forms.saved'))
        ->assertNoJavaScriptErrors();

    $saved = StageProcess::query()->sole();

    expect($saved->stage_id)->toBe((int) $stage->getKey())
        ->and($saved->lot_no_oracle)->toBe('12')
        ->and($saved->greige_received_oracle)->toBe('900.25');

    // Everything the operator left alone is null. An unmeasured quantity is not a quantity of zero, and an
    // unknown reference is not an empty string — the fiddliest part of a screen whose every box is optional.
    $blank = array_values(array_diff(stageProcessOracleColumns(), ['lot_no_oracle', 'greige_received_oracle']));

    foreach ($blank as $column) {
        expect($saved->getAttribute($column))->toBeNull($column.' should have been stored as null');
    }
});
