<?php

use App\Models\User;

it('serves translations for a locale as json with ur falling back to en', function () {
    $res = $this->getJson('/i18n/ur.json')->assertOk();
    expect($res->json('app.nav.dashboard'))->toBe('ڈیش بورڈ')
        ->and($res->json('app.name'))->toBe('Fabric PPC');
});

it('rejects unknown locales', function () {
    $this->getJson('/i18n/fr.json')->assertNotFound();
});

it('switches the user locale and renders rtl', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/locale', ['locale' => 'ur'])->assertRedirect();
    expect($user->fresh()->locale)->toBe('ur');

    $html = $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->getContent();
    expect($html)->toContain('dir="rtl"')->toContain('lang="ur"');
});

it('validates the locale', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
});

it('shares locale props', function () {
    $user = User::factory()->create(['locale' => 'ur']);
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('locale', 'ur')->where('dir', 'rtl')->has('i18nVersion')->has('locales.en'));
});
