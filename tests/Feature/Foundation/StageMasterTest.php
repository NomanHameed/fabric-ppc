<?php

use App\Models\User;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use App\Modules\Production\Models\Lot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Support\Facades\Schema;

/*
 * The Stages and Stage Processes masters (spec §18) and the read API behind them (spec §16).
 * The aliases are edited on the **Stages** master as one comma-separated line and stored as JSON — that
 * round trip is the mapping itself, so it is tested rather than assumed. A stage process no longer carries
 * aliases: resolution is stage-only.
 *
 * A stage process row is an Oracle production line now (migration `2026_10_13_000100`, a deliberate
 * user-directed restructure), so nothing here seeds or looks one up by code: `StageSeeder` seeds stages only
 * and every process row in these tests is made by the factory.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, StageSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');       // masters.view + masters.manage
    $this->viewer = User::factory()->create()->assignRole('viewer'); // masters.view only
    $this->nobody = User::factory()->create();                       // no role at all
});

// ---- the masters ---------------------------------------------------------------------------------------------

it('lists both masters with their field metadata', function () {
    $this->viewer->refresh();

    $this->actingAs($this->viewer)->get('/masters/stages')->assertOk()
        ->assertInertia(fn ($page) => $page->component('masters/MasterForm')
            ->where('resource.key', 'stages')
            ->where('resource.canEdit', false)
            ->has('resource.fields', 6)
            ->has('rows', 15));

    $stage = Stage::where('code', 'DYEING')->firstOrFail();
    StageProcess::factory()->count(2)->create(['stage_id' => $stage->id]);

    $this->actingAs($this->ppc)->get('/masters/stage_processes')->assertOk()
        ->assertInertia(fn ($page) => $page->where('resource.key', 'stage_processes')
            ->where('resource.canEdit', true)
            // The stage link plus the twenty Oracle fields the restructure put on the table.
            ->has('resource.fields', 21)
            ->has('rows', 2));
});

it('creates, updates and deletes a stage', function () {
    $this->actingAs($this->ppc)->post('/masters/stages', [
        'code' => 'REWORK', 'name' => 'Rework', 'description' => 'Sent back in', 'sort_order' => 160, 'is_active' => true,
    ])->assertRedirect();
    $stage = Stage::where('code', 'REWORK')->firstOrFail();

    $this->actingAs($this->ppc)->put("/masters/stages/{$stage->id}", [
        'code' => 'REWORK', 'name' => 'Rework (floor)', 'sort_order' => 165, 'is_active' => false,
    ])->assertRedirect();
    expect($stage->fresh()->name)->toBe('Rework (floor)')->and($stage->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->ppc)->delete("/masters/stages/{$stage->id}")->assertRedirect();
    expect(Stage::where('code', 'REWORK')->exists())->toBeFalse();
});

it('refuses to delete a stage its processes still point at, so it is deactivated instead', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();
    // `stage_processes.stage_id` is restrictOnDelete, so the row itself refuses the delete and
    // MasterController turns the foreign key violation into `forms.in_use`.
    StageProcess::factory()->create(['stage_id' => $stage->id]);

    $this->actingAs($this->ppc)->delete("/masters/stages/{$stage->id}")->assertSessionHasErrors('record');
    expect(Stage::whereKey($stage->id)->exists())->toBeTrue();
});

it('refuses to delete a stage a lot still points at', function () {
    $stage = Stage::where('code', 'GREIGE')->firstOrFail();   // no processes, so only the lot can refuse it
    Lot::factory()->create(['stage_id' => $stage->id]);

    $this->actingAs($this->ppc)->delete("/masters/stages/{$stage->id}")->assertSessionHasErrors('record');
    expect(Stage::whereKey($stage->id)->exists())->toBeTrue();
});

it('lets a process a lot once pointed at be deleted, because nothing references one any more', function () {
    $process = StageProcess::factory()->create(['stage_id' => Stage::where('code', 'DYEING')->value('id')]);
    Lot::factory()->create(['stage_id' => $process->stage_id]);

    $this->actingAs($this->ppc)->delete("/masters/stage_processes/{$process->id}")->assertRedirect()
        ->assertSessionHasNoErrors();
    expect(StageProcess::whereKey($process->id)->exists())->toBeFalse();
});

it('validates the stage code', function () {
    $this->actingAs($this->ppc)->post('/masters/stages', ['code' => 'DYEING', 'name' => 'Dup', 'sort_order' => 1])
        ->assertSessionHasErrors('code');
    $this->actingAs($this->ppc)->post('/masters/stages', ['code' => 'bad code!', 'name' => 'X', 'sort_order' => 1])
        ->assertSessionHasErrors('code');
});

it('stores a stage\'s aliases as JSON and shows them back as one line', function () {
    $this->actingAs($this->ppc)->post('/masters/stages', [
        'code' => 'OVER_DYE', 'name' => 'Over-dyeing', 'sort_order' => 160,
        'status_aliases' => ' OVER DYE ,, OVERDYE/ , OVER DYE ', 'is_active' => true,
    ])->assertRedirect();

    $stage = Stage::where('code', 'OVER_DYE')->firstOrFail();
    // trimmed, blanks dropped, repeats dropped, kept exactly as typed otherwise
    expect($stage->status_aliases)->toBe(['OVER DYE', 'OVERDYE/']);

    $this->actingAs($this->ppc)->get('/masters/stages')
        ->assertInertia(fn ($page) => $page->where(
            'rows.'.rowIndexOf($page->toArray()['props']['rows'], $stage->id).'.status_aliases',
            'OVER DYE, OVERDYE/',
        ));

    // clearing the line empties the mapping rather than leaving the old aliases behind
    $this->actingAs($this->ppc)->put("/masters/stages/{$stage->id}", [
        'code' => 'OVER_DYE', 'name' => 'Over-dyeing', 'sort_order' => 160,
        'status_aliases' => '', 'is_active' => true,
    ])->assertRedirect();
    expect($stage->fresh()->status_aliases)->toBe([]);
});

it('writes every Oracle column of a stage process through the master screen, linked to its stage', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();

    // The twenty columns the user asked for, all present on the table.
    expect(Schema::hasColumns('stage_processes', [
        'stage_id', 'party_oracle_id', 'party_oracle_name', 'po_no', 'ref', 'design_oracle_id',
        'color_oracle_id', 'color_oracle_name', 'clr_status_oracle', 'po_type_oracle', 'date_oracle',
        'lot_no_oracle', 'quality', 'gw_oracle', 'status_oracle', 'greige_received_oracle',
        'greige_in_stock_oracle', 'qty_issued', 'wip', 'finished', 'dispatch',
    ]))->toBeTrue()
        // and the taxonomy columns the restructure dropped are gone, not merely unused
        ->and(Schema::hasColumn('stage_processes', 'code'))->toBeFalse()
        ->and(Schema::hasColumn('stage_processes', 'name'))->toBeFalse()
        ->and(Schema::hasColumn('stage_processes', 'quantity_type'))->toBeFalse()
        ->and(Schema::hasColumn('stage_processes', 'is_wip'))->toBeFalse()
        ->and(Schema::hasColumn('stage_processes', 'is_active'))->toBeFalse()
        ->and(Schema::hasColumn('stage_processes', 'sort_order'))->toBeFalse()
        ->and(Schema::hasColumn('stage_processes', 'status_aliases'))->toBeFalse();

    $this->actingAs($this->ppc)->post('/masters/stage_processes', [
        'stage_id' => $stage->id,
        'party_oracle_id' => 4021, 'party_oracle_name' => 'Al-Karam Textile', 'po_no' => 778899,
        'ref' => 'REF-7788', 'design_oracle_id' => 'D-114', 'color_oracle_id' => 'C-92',
        'color_oracle_name' => 'Navy Blue', 'clr_status_oracle' => 'APPROVED', 'po_type_oracle' => 'DYED',
        'date_oracle' => '18-09-2026', 'lot_no_oracle' => '117', 'quality' => '20x20 / 60x60 VISCOSE',
        'gw_oracle' => '63.50', 'status_oracle' => 'DYEING/',
        'greige_received_oracle' => '1200.50', 'greige_in_stock_oracle' => '200.25', 'qty_issued' => '1000.25',
        'wip' => '600.00', 'finished' => '350.75', 'dispatch' => '49.50',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $process = StageProcess::query()->where('lot_no_oracle', '117')->firstOrFail();
    expect($process->stage_id)->toBe($stage->id)
        ->and($process->stage->code)->toBe('DYEING')                      // the link the user asked for
        ->and($process->party_oracle_id)->toBe(4021)
        ->and($process->party_oracle_name)->toBe('Al-Karam Textile')
        ->and($process->po_no)->toBe(778899)
        ->and($process->ref)->toBe('REF-7788')
        ->and($process->design_oracle_id)->toBe('D-114')
        ->and($process->color_oracle_id)->toBe('C-92')
        ->and($process->color_oracle_name)->toBe('Navy Blue')
        ->and($process->clr_status_oracle)->toBe('APPROVED')
        ->and($process->po_type_oracle)->toBe('DYED')
        // typed as `d-m-Y`, stored at midnight on that day (`createFromFormat('!d-m-Y', …)`)
        ->and($process->date_oracle->format('Y-m-d H:i:s'))->toBe('2026-09-18 00:00:00')
        ->and($process->quality)->toBe('20x20 / 60x60 VISCOSE')
        ->and($process->gw_oracle)->toBe('63.50')
        ->and($process->status_oracle)->toBe('DYEING/')
        // decimal(12,2), not int: 1200.50 metres stay 1200.50 (CLAUDE.md)
        ->and($process->greige_received_oracle)->toBe('1200.50')
        ->and($process->greige_in_stock_oracle)->toBe('200.25')
        ->and($process->qty_issued)->toBe('1000.25')
        ->and($process->wip)->toBe('600.00')
        ->and($process->finished)->toBe('350.75')
        ->and($process->dispatch)->toBe('49.50');

    // and the screen shows the PO date back as `d-m-Y` — the mill's one date format, not the cast's
    // timestamp and not ISO — so what was typed is what comes back
    $this->actingAs($this->ppc)->get('/masters/stage_processes')
        ->assertInertia(fn ($page) => $page->where(
            'rows.'.rowIndexOf($page->toArray()['props']['rows'], $process->id).'.date_oracle',
            '18-09-2026',
        ));
});

it('saves a half-filled process row, because nothing imports into the table yet', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();

    foreach ([1, 2] as $ignored) {
        $this->actingAs($this->ppc)->post('/masters/stage_processes', ['stage_id' => $stage->id])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    // two blank rows are allowed: every Oracle column is nullable and NULLs are distinct in the unique key
    expect(StageProcess::query()->where('stage_id', $stage->id)->count())->toBe(2);
});

it('refuses a second row for the same stage, PO and lot number', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();
    $other = Stage::where('code', 'PRINTING')->firstOrFail();
    $line = ['po_no' => 500100, 'lot_no_oracle' => '12'];

    $this->actingAs($this->ppc)->post('/masters/stage_processes', $line + ['stage_id' => $stage->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    // the same line twice under one stage is the duplicate the old unique(stage, code) used to catch
    $this->actingAs($this->ppc)->post('/masters/stage_processes', $line + ['stage_id' => $stage->id])
        ->assertSessionHasErrors('lot_no_oracle');

    // the same lot number under another PO, or under another stage, is a different line
    $this->actingAs($this->ppc)->post('/masters/stage_processes', ['stage_id' => $stage->id, 'po_no' => 500200, 'lot_no_oracle' => '12'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->ppc)->post('/masters/stage_processes', $line + ['stage_id' => $other->id])
        ->assertRedirect()->assertSessionHasNoErrors();
});

it('validates the stage link and the Oracle field widths', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();

    $this->actingAs($this->ppc)->post('/masters/stage_processes', ['stage_id' => 999999])
        ->assertSessionHasErrors('stage_id');
    $this->actingAs($this->ppc)->post('/masters/stage_processes', [])
        ->assertSessionHasErrors('stage_id');
    $this->actingAs($this->ppc)->post('/masters/stage_processes', ['stage_id' => $stage->id, 'lot_no_oracle' => '12345678901'])
        ->assertSessionHasErrors('lot_no_oracle');
    $this->actingAs($this->ppc)->post('/masters/stage_processes', ['stage_id' => $stage->id, 'date_oracle' => 'not a date'])
        ->assertSessionHasErrors('date_oracle');
    $this->actingAs($this->ppc)->post('/masters/stage_processes', ['stage_id' => $stage->id, 'wip' => 'lots'])
        ->assertSessionHasErrors('wip');
    $this->actingAs($this->ppc)->post('/masters/stage_processes', ['stage_id' => $stage->id, 'gw_oracle' => '12345.67'])
        ->assertSessionHasErrors('gw_oracle');
});

it('takes the PO date only as d-m-Y, pinned to midnight', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();

    // ISO is what the screen used to show and is not what it takes now, and the loose `date` rule used to
    // let a time of day into a column every other row holds at midnight.
    foreach (['2026-09-18', '18-09-2026 14:30', '2026-09-18 14:30', '18/09/2026', '32-01-2026'] as $bad) {
        $this->actingAs($this->ppc)->post('/masters/stage_processes', ['stage_id' => $stage->id, 'date_oracle' => $bad])
            ->assertSessionHasErrors('date_oracle');
    }

    $this->actingAs($this->ppc)->post('/masters/stage_processes', [
        'stage_id' => $stage->id, 'lot_no_oracle' => '501', 'date_oracle' => '18-09-2026',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(StageProcess::query()->where('lot_no_oracle', '501')->sole()->date_oracle->format('Y-m-d H:i:s'))
        ->toBe('2026-09-18 00:00:00');
});

it('refuses a quantity with a third decimal place instead of rounding it away', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();

    // `decimal(12,2)` cannot hold it: MySQL would round it silently and SQLite would keep it, so the two
    // drivers would disagree on a raw SUM(). It has to be a validation error.
    foreach (StageProcess::QUANTITY_COLUMNS as $column) {
        $this->actingAs($this->ppc)->post('/masters/stage_processes', ['stage_id' => $stage->id, $column => '1200.567'])
            ->assertSessionHasErrors($column);
    }

    // `gw_oracle` is `decimal(6,2)` and is guarded the same way
    $this->actingAs($this->ppc)->post('/masters/stage_processes', ['stage_id' => $stage->id, 'gw_oracle' => '63.505'])
        ->assertSessionHasErrors('gw_oracle');

    // nothing was written, and two decimal places are still accepted
    expect(StageProcess::query()->count())->toBe(0);

    $this->actingAs($this->ppc)->post('/masters/stage_processes', [
        'stage_id' => $stage->id, 'lot_no_oracle' => '601', 'wip' => '1200.56', 'gw_oracle' => '63.5', 'finished' => '900',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $saved = StageProcess::query()->where('lot_no_oracle', '601')->sole();
    expect($saved->wip)->toBe('1200.56')->and($saved->gw_oracle)->toBe('63.50')->and($saved->finished)->toBe('900.00');
});

it('finds a process row by its PO date in query mode', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();
    $wanted = StageProcess::factory()->create(['stage_id' => $stage->id, 'date_oracle' => '2026-09-18 00:00:00']);
    $other = StageProcess::factory()->create(['stage_id' => $stage->id, 'date_oracle' => '2026-09-19 00:00:00']);

    // F7 → type the date → F8. The field is a `datetime` criterion, so the day is compared with whereDate
    // and the three formats QueryCriteria accepts all land on the same row.
    foreach (['18-09-2026', '18/09/2026', '2026-09-18'] as $typed) {
        $rows = $this->actingAs($this->ppc)->get('/masters/stage_processes?q[date_oracle]='.urlencode($typed))
            ->assertOk()->assertSessionHasNoErrors()
            ->viewData('page')['props']['rows'];

        expect(collect($rows)->pluck('id')->all())->toBe([$wanted->id], $typed.' found the wrong rows');
    }

    // and a range spans both days
    $rows = $this->actingAs($this->ppc)->get('/masters/stage_processes?q[date_oracle]='.urlencode('18-09-2026..19-09-2026'))
        ->assertOk()->viewData('page')['props']['rows'];
    expect(collect($rows)->pluck('id')->all())->toBe([$wanted->id, $other->id]);
});

it('orders the process rows of one stage the same way on every load', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();
    $other = Stage::where('code', 'FINISHING')->firstOrFail();
    // Same stage, so `order by stage_id` alone leaves these four to the database's own whim.
    $ids = collect(range(1, 4))->map(fn (int $i) => StageProcess::factory()->create(['stage_id' => $stage->id])->id)->all();
    $elsewhere = StageProcess::factory()->create(['stage_id' => $other->id]);

    foreach ([1, 2] as $ignored) {
        $rows = $this->actingAs($this->ppc)->get('/masters/stage_processes')->assertOk()
            ->viewData('page')['props']['rows'];

        // grouped by stage, then in entry order inside the stage — the id breaks every tie
        expect(collect($rows)->pluck('id')->all())->toBe(
            $stage->id < $other->id ? [...$ids, $elsewhere->id] : [$elsewhere->id, ...$ids],
        );
    }
});

it('forbids editing either master without masters.manage', function () {
    $this->actingAs($this->viewer)->post('/masters/stages', ['code' => 'X', 'name' => 'X', 'sort_order' => 1])->assertForbidden();
    $this->actingAs($this->viewer)->post('/masters/stage_processes', ['stage_id' => 1])->assertForbidden();
});

// ---- the API ------------------------------------------------------------------------------------------------

it('lists the active stages with their process counts', function () {
    Stage::query()->where('code', 'COMMERCIAL')->update(['is_active' => false]);
    $inactive = Stage::factory()->create(['code' => 'GONE', 'name' => 'Gone', 'is_active' => false]);
    StageProcess::factory()->count(2)->create(['stage_id' => Stage::where('code', 'PREP')->value('id')]);

    $res = $this->actingAs($this->viewer)->getJson('/api/stages')->assertOk();

    $codes = array_column($res->json('data'), 'code');
    expect($codes)->toContain('DYEING')->and($codes)->not->toContain('GONE')
        ->and($res->json('data.0.code'))->toBe('GREIGE')                     // in sort order
        ->and($res->json('data.0.processes_count'))->toBe(0)                 // the seeder seeds no processes
        ->and($res->json('data.1.code'))->toBe('PREP')
        ->and($res->json('data.1.processes_count'))->toBe(2)
        // the mapping travels with the stage: the list is what the selects and the mapping screen read
        ->and($res->json('data.0.status_aliases'))->toBe([Stage::BLANK_ALIAS])
        ->and($res->json('data.1.status_aliases'))->toBe(['BLEACHING', 'PADDING', 'SINGEING', 'MERCERIZING']);

    // a stage that claims nothing answers with an empty list, never null: the screen shows "claims nothing"
    $bare = collect($res->json('data'))->firstWhere('code', 'INSPECTION');
    expect($bare['status_aliases'])->toBe([]);

    expect($inactive->fresh()->is_active)->toBeFalse();
});

it('returns the stage aliases as a clean list of strings, whatever the JSON column holds', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();
    // a loosely typed write from an older version of the column
    $stage->forceFill(['status_aliases' => ['  DYEING  ', '', null, 'DYED/']])->save();
    $empty = Stage::factory()->create(['code' => 'NOALIAS', 'name' => 'No alias', 'status_aliases' => null]);

    $this->actingAs($this->viewer)->getJson("/api/stages/{$stage->id}")->assertOk()
        ->assertJsonPath('data.status_aliases', ['DYEING', 'DYED/']);

    $this->actingAs($this->viewer)->getJson("/api/stages/{$empty->id}")->assertOk()
        ->assertJsonPath('data.status_aliases', []);
});

it('returns one stage with the production lines filed under it', function () {
    $stage = Stage::where('code', 'FINISHING')->firstOrFail();
    $first = StageProcess::factory()->create(['stage_id' => $stage->id, 'lot_no_oracle' => '41']);
    $second = StageProcess::factory()->create(['stage_id' => $stage->id, 'lot_no_oracle' => '42']);
    // another stage's line must not leak into this one
    StageProcess::factory()->create(['stage_id' => Stage::where('code', 'DYEING')->value('id')]);

    $res = $this->actingAs($this->viewer)->getJson("/api/stages/{$stage->id}")->assertOk();

    expect($res->json('data.code'))->toBe('FINISHING')
        ->and($res->json('data.status_aliases'))->toBe($stage->aliasList())
        ->and($res->json('data.status_aliases'))->toContain('SANFO/CAL RP')
        // insertion order: `sort_order` and `code` went with the taxonomy
        ->and(array_column($res->json('data.processes'), 'id'))->toBe([$first->id, $second->id])
        ->and(array_column($res->json('data.processes'), 'lot_no_oracle'))->toBe(['41', '42']);
});

it('returns the Oracle fields of a production line, with its quantities as JSON numbers', function () {
    $stage = Stage::where('code', 'DISPATCH')->firstOrFail();
    $process = StageProcess::factory()->create([
        'stage_id' => $stage->id, 'lot_no_oracle' => '117', 'po_no' => 778899, 'party_oracle_id' => 4021,
        'party_oracle_name' => 'Al-Karam Textile', 'ref' => 'REF-7788', 'design_oracle_id' => 'D-114',
        'color_oracle_id' => 'C-92', 'color_oracle_name' => 'Navy Blue', 'clr_status_oracle' => 'APPROVED',
        'po_type_oracle' => 'DYED', 'date_oracle' => '2026-09-18', 'quality' => '20x20 VISCOSE',
        'gw_oracle' => 63.5, 'status_oracle' => 'DISPATCHED/', 'greige_received_oracle' => 1200.5,
        'greige_in_stock_oracle' => 200.25, 'qty_issued' => 1000.25, 'wip' => 600.75, 'finished' => 350.75,
        'dispatch' => 49.5,
    ]);

    $res = $this->actingAs($this->viewer)->getJson("/api/stages/{$stage->id}/processes")->assertOk();
    $row = $res->json('data.0');

    expect($row['id'])->toBe($process->id)
        ->and($row['stage_id'])->toBe($stage->id)
        ->and($row['lot_no_oracle'])->toBe('117')
        ->and($row['po_no'])->toBe(778899)
        ->and($row['party_oracle_id'])->toBe(4021)
        ->and($row['party_oracle_name'])->toBe('Al-Karam Textile')
        ->and($row['ref'])->toBe('REF-7788')
        ->and($row['design_oracle_id'])->toBe('D-114')
        ->and($row['color_oracle_id'])->toBe('C-92')
        ->and($row['color_oracle_name'])->toBe('Navy Blue')
        ->and($row['clr_status_oracle'])->toBe('APPROVED')
        ->and($row['po_type_oracle'])->toBe('DYED')
        ->and($row['date_oracle'])->toBe('2026-09-18')                        // a date, not a timestamp
        ->and($row['quality'])->toBe('20x20 VISCOSE')
        ->and($row['status_oracle'])->toBe('DISPATCHED/')
        // JSON numbers, never the decimal cast's "1200.50"
        ->and($row['gw_oracle'])->toBe(63.5)
        ->and($row['greige_received_oracle'])->toBe(1200.5)
        ->and($row['greige_in_stock_oracle'])->toBe(200.25)
        ->and($row['qty_issued'])->toBe(1000.25)
        ->and($row['wip'])->toBe(600.75)
        ->and($row['wip'])->not->toBeString()
        ->and($row['finished'])->toBe(350.75)
        ->and($row['dispatch'])->toBe(49.5)
        // the mapping lives on the stage, never on a line
        ->and($stage->aliasList())->toBe(['DISPATCHED', 'RF-DISP', 'DISP *'])
        ->and($row)->not->toHaveKey('status_aliases')
        ->and($row)->not->toHaveKey('code')
        ->and($row)->not->toHaveKey('is_active');

    // and the stage payload is where a screen reads the aliases
    $this->actingAs($this->viewer)->getJson("/api/stages/{$stage->id}")->assertOk()
        ->assertJsonPath('data.status_aliases', ['DISPATCHED', 'RF-DISP', 'DISP *']);
});

it('leaves an empty production line\'s columns null rather than zero', function () {
    $stage = Stage::where('code', 'GREIGE')->firstOrFail();
    $process = StageProcess::factory()->create(array_merge(
        ['stage_id' => $stage->id],
        array_fill_keys([
            'party_oracle_id', 'party_oracle_name', 'po_no', 'ref', 'design_oracle_id', 'color_oracle_id',
            'color_oracle_name', 'clr_status_oracle', 'po_type_oracle', 'date_oracle', 'lot_no_oracle',
            'quality', 'gw_oracle', 'status_oracle', 'greige_received_oracle', 'greige_in_stock_oracle',
            'qty_issued', 'wip', 'finished', 'dispatch',
        ], null),
    ));

    $res = $this->actingAs($this->viewer)->getJson("/api/stage-processes/{$process->id}")->assertOk();

    expect(array_values(array_diff_key($res->json('data'), array_flip(['id', 'stage_id', 'stage']))))
        ->each->toBeNull();
});

it('answers for one production line by id, with the stage it belongs to', function () {
    $process = StageProcess::factory()->create([
        'stage_id' => Stage::where('code', 'FINISHING')->value('id'), 'lot_no_oracle' => '77',
    ]);

    $this->actingAs($this->viewer)->getJson("/api/stage-processes/{$process->id}")->assertOk()
        ->assertJsonPath('data.lot_no_oracle', '77')
        ->assertJsonPath('data.stage.code', 'FINISHING');
});

it('404s for a stage or process that does not exist', function () {
    $this->actingAs($this->viewer)->getJson('/api/stages/999999')->assertNotFound();
    $this->actingAs($this->viewer)->getJson('/api/stages/999999/processes')->assertNotFound();
    $this->actingAs($this->viewer)->getJson('/api/stage-processes/999999')->assertNotFound();
});

it('needs masters.view', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();
    $process = StageProcess::factory()->create(['stage_id' => $stage->id]);

    foreach (['/api/stages', "/api/stages/{$stage->id}", "/api/stages/{$stage->id}/processes", "/api/stage-processes/{$process->id}"] as $url) {
        $this->actingAs($this->nobody)->getJson($url)->assertForbidden();
    }
});

it('needs a session', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();

    foreach (['/api/stages', "/api/stages/{$stage->id}", "/api/stages/{$stage->id}/processes"] as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
});

/** The row index of a record in the master screen's rows (they come back in the resource's own order). */
function rowIndexOf(array $rows, int $id): int
{
    foreach (array_values($rows) as $i => $row) {
        if ((int) $row['id'] === $id) {
            return $i;
        }
    }

    throw new RuntimeException("row {$id} is not on the screen");
}
