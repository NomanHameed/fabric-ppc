<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Jobs\CommitOracleImport;
use App\Modules\OracleSync\Models\OracleImport;
use Database\Seeders\PartySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    Storage::fake('local');
    $this->seed([RolesAndPermissionsSeeder::class, PartySeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->manager = User::factory()->create()->assignRole('manager');
    $this->file = fn () => new UploadedFile(base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), 'wip-sep.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
});

it('uploads and previews an export', function () {
    $res = $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => ($this->file)()]);
    $import = OracleImport::firstOrFail();
    $res->assertRedirect("/oracle/imports/{$import->id}");
    expect($import->label)->toBe('wip-sep.xlsx')->and($import->new_count)->toBe(471);

    $this->actingAs($this->ppc)->get("/oracle/imports/{$import->id}")->assertInertia(fn ($page) => $page
        ->component('oracle/ImportPreview')->where('tab', 'error')->has('rows', 152)->has('import.unresolved_parties', 4));
});

it('shows a tab on request', function () {
    $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => ($this->file)()]);
    $import = OracleImport::first();
    $this->actingAs($this->ppc)->get("/oracle/imports/{$import->id}?tab=new")
        ->assertInertia(fn ($page) => $page->where('tab', 'new')->has('rows', 471)->where('rows.0.po_no', fn ($v) => $v > 260000));
});

it('rejects non-xlsx uploads and files without the Oracle columns', function () {
    $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => UploadedFile::fake()->create('x.csv', 1, 'text/csv')])
        ->assertSessionHasErrors('file');

    $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    $w = new Writer;
    $w->openToFile($path);
    $w->addRow(Row::fromValues(['PARTY']));
    $w->close();
    $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => new UploadedFile($path, 'bad.xlsx', null, null, true)])
        ->assertSessionHasErrors('file');
    expect(OracleImport::count())->toBe(0);
});

it('rejects a corrupt xlsx that is not a valid zip', function () {
    $this->actingAs($this->ppc)->post('/oracle/imports', [
        'file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not a zip'),
    ])->assertSessionHasErrors('file');

    expect(OracleImport::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('oracle-imports'))->toBeEmpty();
});

it('lets managers view but not run imports', function () {
    $this->actingAs($this->manager)->get('/oracle/imports')->assertOk()->assertInertia(fn ($p) => $p->where('canRun', false));
    $this->actingAs($this->manager)->post('/oracle/imports', ['file' => ($this->file)()])->assertForbidden();
});

it('resolves parties and queues the commit', function () {
    Queue::fake();
    $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => ($this->file)()]);
    $import = OracleImport::first();

    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/commit")->assertSessionHasErrors('import');

    foreach ([397 => 'U&I GARMENTS', 485 => 'IMAGE', 561 => 'AL HAMD TEXTILE'] as $code => $name) {
        $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/parties/{$code}", ['mode' => 'link', 'party_id' => Party::findByName($name)->id])
            ->assertRedirect();
    }
    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/parties/562", ['mode' => 'create', 'type' => 'brand'])->assertRedirect();
    expect($import->fresh()->error_count)->toBe(0);

    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/commit")->assertRedirect();
    Queue::assertPushed(CommitOracleImport::class, fn ($job) => $job->importId === $import->id && $job->userId === $this->ppc->id);
});

it('discards a preview', function () {
    $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => ($this->file)()]);
    $import = OracleImport::first();
    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/discard")->assertRedirect('/oracle/imports');
    expect($import->fresh()->status)->toBe('discarded');
});
