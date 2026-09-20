<?php

use App\Models\User;
use App\Modules\Foundation\Query\QueryCriteria;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Schema::create('qc_items', function (Blueprint $t) {
        $t->id();
        $t->string('code')->nullable();
        $t->decimal('qty', 12, 2)->nullable();
        $t->date('dated')->nullable();
        $t->boolean('active')->default(true);
    });
    DB::table('qc_items')->insert([
        ['code' => '261041', 'qty' => 5447, 'dated' => '2026-08-17', 'active' => true],
        ['code' => '261059', 'qty' => 5522, 'dated' => '2026-08-19', 'active' => true],
        ['code' => '260958', 'qty' => 2565, 'dated' => '2026-07-01', 'active' => false],
        ['code' => null, 'qty' => null, 'dated' => null, 'active' => true],
    ]);
    $this->fields = [
        'po' => ['column' => 'code', 'type' => 'text'],
        'qty' => ['column' => 'qty', 'type' => 'number'],
        'date' => ['column' => 'dated', 'type' => 'date'],
        'active' => ['column' => 'active', 'type' => 'bool'],
    ];
    $this->codes = fn (array $criteria) => QueryCriteria::apply(DB::table('qc_items'), $criteria, $this->fields)
        ->orderBy('id')->pluck('code')->all();
});

it('ignores blanks and unknown keys', function () {
    expect(($this->codes)(['po' => '', 'nope' => 'x']))->toHaveCount(4);
});

it('matches text exactly or with wildcards', function () {
    expect(($this->codes)(['po' => '261041']))->toBe(['261041'])
        ->and(($this->codes)(['po' => '2610%']))->toBe(['261041', '261059'])
        ->and(($this->codes)(['po' => '26095_']))->toBe(['260958'])
        ->and(($this->codes)(['po' => '#NULL']))->toBe([null]);
});

it('supports number operators and ranges', function () {
    expect(($this->codes)(['qty' => '>5000']))->toBe(['261041', '261059'])
        ->and(($this->codes)(['qty' => '<=2565']))->toBe(['260958'])
        ->and(($this->codes)(['qty' => '2500..5500']))->toBe(['261041', '260958'])
        ->and(($this->codes)(['qty' => '5522']))->toBe(['261059'])
        ->and(($this->codes)(['qty' => '!=5522']))->toBe(['261041', '260958']);
});

it('supports dates in local formats', function () {
    expect(($this->codes)(['date' => '17-08-2026']))->toBe(['261041'])
        ->and(($this->codes)(['date' => '>=18/08/2026']))->toBe(['261059'])
        ->and(($this->codes)(['date' => '2026-07-01..2026-08-17']))->toBe(['261041', '260958']);
});

it('supports booleans', function () {
    expect(($this->codes)(['active' => 'N']))->toBe(['260958']);
});

it('rejects unparseable numbers with a field error', function () {
    ($this->codes)(['qty' => '>abc']);
})->throws(ValidationException::class);

it('rejects array-valued criteria with a validation error (M-5)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $ppc = User::factory()->create()->assignRole('ppc');
    $this->actingAs($ppc)->get('/masters/departments?q[code][]=x')->assertSessionHasErrors('code');
});
