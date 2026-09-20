<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

function inertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
}

it('shows a reference id for unexpected errors and logs the same id', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/boom', fn () => throw new RuntimeException('kaboom'));
    Log::spy();

    $res = $this->actingAs(User::factory()->create())->withHeaders(inertiaHeaders())->get('/boom');

    $res->assertStatus(500);
    expect($res->json('component'))->toBe('Error');
    $ref = $res->json('props.reference');
    expect($ref)->toMatch('/^[0-9A-F]{10}$/')
        ->and($res->json('props.status'))->toBe(500);
    Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => $msg === 'kaboom' && ($ctx['ref'] ?? null) === $ref)->once();
});

it('leaves expected errors (404, validation, auth) to the default handlers', function () {
    config(['app.debug' => false]);
    Log::spy();

    $this->withHeaders(inertiaHeaders())->get('/dashboard')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->withHeaders(inertiaHeaders())->get('/no-such-page')->assertNotFound();
    Log::shouldNotHaveReceived('error');
});
