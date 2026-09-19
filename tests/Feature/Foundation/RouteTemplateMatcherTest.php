<?php

use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Foundation\Routing\RouteTemplateMatcher;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->matcher = new RouteTemplateMatcher;
});

it('seeds the four default templates with ordered steps', function () {
    expect(RouteTemplate::pluck('name')->sort()->values()->all())
        ->toBe(['Default', 'Dyed - Standard', 'Half Bleach', 'Printed - Standard']);
    $dyed = RouteTemplate::where('name', 'Dyed - Standard')->first();
    expect($dyed->steps->map(fn ($s) => $s->operation->name)->all())
        ->toBe(['SINGING', 'SCOUR+BLEACH', 'MERCERIZING', 'REACTIVE DYE', 'DRY', 'DYE FINISH FOR SANFORIZING', 'SANFORIZE']);
});

it('matches by po type', function () {
    expect($this->matcher->match('PRINTED', '110X78/36X120 100% VISCOSE [AJ]')?->name)->toBe('Printed - Standard')
        ->and($this->matcher->match('dyed', 'anything')?->name)->toBe('Dyed - Standard');
});

it('falls back to the default template', function () {
    expect($this->matcher->match('PFGD', null)?->name)->toBe('Default');
});

it('prefers a quality-specific template over a generic one at equal priority', function () {
    $special = RouteTemplate::create(['name' => 'Dyed Viscose', 'po_type' => 'DYED', 'quality_pattern' => '%VISCOSE%', 'priority' => 10]);
    expect($this->matcher->match('DYED', '88X60/30X30 100% VISCOSE [AJ]')?->id)->toBe($special->id)
        ->and($this->matcher->match('DYED', '52X44/20X20 100% CTN [AJ]')?->name)->toBe('Dyed - Standard');
});

it('ignores inactive templates', function () {
    RouteTemplate::where('name', 'Printed - Standard')->update(['is_active' => false]);
    expect($this->matcher->match('PRINTED', null)?->name)->toBe('Default');
});
