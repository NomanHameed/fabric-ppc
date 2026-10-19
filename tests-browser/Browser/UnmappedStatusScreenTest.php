<?php

use App\Modules\Foundation\Models\Stage;
use App\Modules\Production\Models\Lot;
use Database\Seeders\StageSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Spec §10: "Unmapped Production Statuses" and the one action that fixes a gap.
 *
 * The part only a browser can prove is the read-only line beside the stage select — what the chosen stage
 * already claims. It is rendered from the stage list `StageSelect` loaded for its own options and emitted
 * through `@loaded`, so it exists only once the API call has resolved and a stage has been picked: three
 * client-side states (nothing picked, a stage with aliases, a stage with none) that no server assertion can
 * see. The mapping itself is then driven end to end, through the same select.
 *
 * Gated on `masters.manage`, which the PPC role (`browserLogin()`'s default) holds.
 *
 * Every element is addressed by an **explicit** selector (`[id="map-stage"]`, not `map-stage`). Pest's
 * browser plugin guesses what a selector means (Pest\Browser\Support\Selector::isExplicit): a bare word
 * carries no CSS punctuation, so it is first tried as `[id=…]`/`[name=…]` and, if that matches *nothing at
 * that instant*, silently re-read as page **text** — which matches nothing either, and the action that
 * follows then waits for it with no timeout and hangs forever instead of failing. `map-stage` only appears
 * once a row is picked, so it is exactly the kind of selector that loses that race.
 */

/** A lot carrying exactly this raw status, written past the ledger: the point is the status, not how it got there. */
function browserLotWithStatus(string $status): Lot
{
    $lot = Lot::factory()->create(['greige_received_oracle' => 100]);
    DB::table('lots')->where('id', $lot->getKey())->update(['status_oracle' => $status]);

    return $lot->fresh();
}

it('names the statuses the chosen stage already claims, and says so when it claims none', function () {
    browserLogin();
    test()->seed(StageSeeder::class);

    browserLotWithStatus('WEIRD ONE/');
    $dyeing = Stage::query()->where('code', 'DYEING')->firstOrFail();          // claims DYEING
    $inspection = Stage::query()->where('code', 'INSPECTION')->firstOrFail();  // claims nothing

    expect($dyeing->aliasList())->toBe(['DYEING'])
        ->and($inspection->aliasList())->toBe([]);

    $page = visit('/production/unmapped-statuses');

    // No row picked: there is no mapping block, so neither line can be on the screen.
    $page->assertSee(__('production.unmapped.title'))
        ->assertSee('WEIRD ONE/')
        ->assertDontSee(__('production.unmapped.stage_claims'))
        ->assertDontSee(__('production.unmapped.stage_claims_none'))
        ->assertNoJavaScriptErrors();

    // The row is picked but no stage is chosen yet: still neither line.
    $page->click('WEIRD ONE/')
        ->assertSee(__('production.unmapped.map_confirm'))
        ->assertDontSee(__('production.unmapped.stage_claims'))
        ->assertDontSee(__('production.unmapped.stage_claims_none'));

    // A stage with aliases: the line appears and lists them.
    $page->select('[id="map-stage"]', (string) $dyeing->getKey())
        ->assertSee(__('production.unmapped.stage_claims'))
        ->assertSeeIn('p span.font-mono', 'DYEING')
        ->assertDontSee(__('production.unmapped.stage_claims_none'));

    // A stage with none: the other line replaces it.
    $page->select('[id="map-stage"]', (string) $inspection->getKey())
        ->assertSee(__('production.unmapped.stage_claims_none'))
        ->assertDontSee(__('production.unmapped.stage_claims'))
        ->assertNoJavaScriptErrors();
});

it('maps the status to the chosen stage, moves its lots and drops the row from the report', function () {
    browserLogin();
    test()->seed(StageSeeder::class);

    $lot = browserLotWithStatus('WEIRD ONE/');
    $dyeing = Stage::query()->where('code', 'DYEING')->firstOrFail();

    visit('/production/unmapped-statuses')
        ->assertSee('WEIRD ONE/')
        ->click('WEIRD ONE/')
        ->select('[id="map-stage"]', (string) $dyeing->getKey())
        ->click(__('production.unmapped.map_confirm'))
        ->assertSee(__('production.unmapped.none'))
        ->assertDontSee('WEIRD ONE/')
        ->assertNoJavaScriptErrors();

    // The alias is stored normalised (the form the resolver compares), and the lot moved with it.
    expect($dyeing->fresh()?->aliasList())->toBe(['DYEING', 'WEIRD ONE'])
        ->and($lot->fresh()?->stage_id)->toBe((int) $dyeing->getKey());
});
