<?php

use App\Models\User;
use App\Modules\Production\Http\Controllers\SearchController;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * The top-bar Lot / PO search (spec §4.1, §6.3.2): the lots LOV's exact-lookup rules across every state — a full
 * lot number, its last 4 digits, or a PO number — with one hit redirecting and several (or none) showing the chooser.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->actingAs($this->viewer);
    // explicit PO numbers, so no factory PO can collide with a lot number under test
    $this->po1 = Contract::factory()->create(['po_no' => 261071]);
    $this->po2 = Contract::factory()->create(['po_no' => 261072]);
    $this->a = Lot::factory()->create(['lot_no_oracle' => '264899', 'contract_id' => $this->po1->id, 'design' => 'D1', 'color_oracle_name' => 'RED']);
    $this->b = Lot::factory()->create(['lot_no_oracle' => '264899', 'contract_id' => $this->po2->id, 'design' => 'D2', 'color_oracle_name' => 'BLUE', 'state' => 'closed']);
    $this->c = Lot::factory()->create(['lot_no_oracle' => '265025', 'contract_id' => $this->po1->id, 'state' => 'cancelled']);
});

it('redirects a unique lot number straight to the lot, whatever its state', function () {
    $this->get('/search?q=265025')->assertRedirect("/inquiry/lots/{$this->c->id}");
    $this->get('/search?q=%20265025%20')->assertRedirect("/inquiry/lots/{$this->c->id}");
});

it('offers a chooser when several lots share the number, with PO, party, design, colour, state and label', function () {
    $this->get('/search?q=264899')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('inquiry/SearchResults')->where('q', '264899')
            ->has('lots', 2)->where('lotsTotal', 2)->has('contracts', 0)
            ->has('lots.0', fn (AssertableInertia $l) => $l->where('id', $this->b->id)->where('lot_no_oracle', '264899')->where('po_no', 261072) // latest first
                ->where('party', $this->po2->party->name)->where('design', 'D2')->where('color_oracle_name', 'BLUE')->where('state', 'closed')->where('status_oracle', null)
                ->has('quality')->has('greige_in_stock_oracle')->has('wip')->has('finished'))
            ->has('lots.1', fn (AssertableInertia $l) => $l->where('id', $this->a->id)->where('po_no', 261071)->where('design', 'D1')->etc()));
});

it('looks up the last 4 digits and redirects when only one lot ends with them', function () {
    $this->get('/search?q=5025')->assertRedirect("/inquiry/lots/{$this->c->id}");
    $this->get('/search?q=4899')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 2));
    $this->get('/search?q=899')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 2)); // shorter suffixes match too
});

it('redirects a PO number to the PO inquiry, and shows a chooser when a number is both a lot and a PO', function () {
    $this->get('/search?q=261072')->assertRedirect("/inquiry/contracts/{$this->po2->id}");

    Contract::factory()->create(['po_no' => 265025]);
    $this->get('/search?q=265025')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1)->has('contracts', 1)
            ->where('contracts.0.po_no', 265025)->has('contracts.0.party')->has('contracts.0.po_date'));
});

it('shows the empty state for text, an unknown number and no query', function () {
    $this->get('/search?q=abc')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('inquiry/SearchResults')->where('q', 'abc')->has('lots', 0)->has('contracts', 0)->where('lotsTotal', 0));
    $this->get('/search?q=111111')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 0)->has('contracts', 0));
    $this->get('/search')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('q', '')->has('lots', 0)->has('contracts', 0));
    $this->get('/search?q[]=1')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('q', '')); // an array query is treated as empty
});

it('caps the chooser at 200 lots and reports the total', function () {
    for ($i = 0; $i < 201; $i++) {
        Lot::factory()->create(['lot_no_oracle' => '300777', 'contract_id' => $this->po1->id]);
    }
    $this->get('/search?q=0777')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', SearchController::ROW_CAP)->where('lotsTotal', 201));
});

it('needs inquiry.view', function () {
    $this->actingAs(User::factory()->create())->get('/search?q=265025')->assertForbidden();
    auth()->logout();
    $this->get('/search?q=265025')->assertRedirect('/login');
});
