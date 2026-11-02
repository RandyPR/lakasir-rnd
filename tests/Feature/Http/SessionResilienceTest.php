<?php

/*
|--------------------------------------------------------------------------
| A failed Livewire request is not a blank overlay, and a screen left open keeps its session
|--------------------------------------------------------------------------
|
| Livewire opens a full-screen overlay with the body of any failed answer. Three things made that body empty or
| invisible: the service worker answered a dropped connection with an empty 408, the exception handler turned every
| Livewire failure into JSON, and a session that had ended failed a poll with a 403 nobody could read.
*/

use App\Models\Tenants\User;
use Illuminate\Support\Facades\Route;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

uses(RefreshDatabaseWithTenant::class);

/** What Livewire sends with every update: a JSON body and the X-Livewire header. */
function livewireHeaders(): array
{
    return ['X-Livewire' => '', 'Content-Type' => 'application/json', 'Accept' => 'text/html, application/xhtml+xml'];
}

beforeEach(function () {
    // The panel's theme is a Vite asset; the test run has no public/build.
    $this->withoutVite();

    Route::middleware('web')->post('/_probe/boom', fn () => throw new RuntimeException('probe failure'));
    Route::middleware(['web', 'auth'])->post('/_probe/private', fn () => response()->noContent());
});

test('the session ping answers 204 while the session lives, and is never cached', function () {
    $response = actingAs(User::where('email', 'toko_testing@mail.com')->firstOrFail())
        ->get('/session-ping', ['Accept' => 'application/json']);

    $response->assertNoContent();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('the session ping answers 401 when there is no session', function () {
    get('/session-ping', ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('a failing Livewire update is answered with a page Livewire can show, not JSON', function () {
    $response = withHeaders(livewireHeaders())->postJson('/_probe/boom', []);

    expect($response->headers->get('Content-Type'))->toContain('text/html');
});

test('a failing JSON request without the Livewire header is still answered with JSON (the mobile API)', function () {
    $response = withHeaders(['Content-Type' => 'application/json'])->postJson('/_probe/boom', []);

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

test('a Livewire update without a session gets a 401, not a redirect to the login page', function () {
    withHeaders(livewireHeaders())->post('/_probe/private', [])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('a browser request without a session is still redirected to the login page', function () {
    $this->post('/_probe/private', [])->assertRedirect();
});

test('every panel page carries the script that replaces the blank overlay', function () {
    $guest = get('/member/login');
    $guest->assertOk()->assertSee('data-livewire-resilience', false);

    $html = actingAs(User::where('email', 'toko_testing@mail.com')->firstOrFail())->get('/member')->getContent();

    expect($html)->toContain('data-livewire-resilience')
        ->and($html)->toContain('/session-ping')
        // Js::from() writes the config as a JSON string, quotes as \u0022.
        ->and($html)->toContain('\u0022authenticated\u0022:true');
});

test('the login page script has no session to watch', function () {
    expect(get('/member/login')->getContent())->toContain('\u0022authenticated\u0022:false');
});

test('the service worker never answers a request with an invented empty page', function () {
    $worker = file_get_contents(public_path('serviceworker.js'));

    // The empty "request timed out" answer is what Livewire showed as a blank overlay.
    expect($worker)->not->toContain('new Response(')
        // Only GET requests are handled: a POST (Livewire's update) goes straight to the network.
        ->and($worker)->toContain("event.request.method !== 'GET'")
        ->and($worker)->toContain('Response.error()');
});
