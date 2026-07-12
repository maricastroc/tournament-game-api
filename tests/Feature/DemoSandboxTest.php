<?php

declare(strict_types=1);

use App\Models\Fixture;
use App\Models\Tournament;
use App\Models\User;
use Database\Seeders\TournamentDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/** @return array{token: string, sandbox: int} */
function demoLogin(object $test): array
{
    $body = $test->postJson('/api/login', ['email' => 'demo@bracket.test', 'password' => 'password'])
        ->assertOk()
        ->json();

    return ['token' => $body['token'], 'sandbox' => $body['sandbox_tournament_id']];
}

/** A finished group-stage fixture (tie_id null) belonging to the given tournament. */
function playedGroupFixture(int $tournamentId): Fixture
{
    return Fixture::where('tournament_id', $tournamentId)
        ->whereNull('tie_id')
        ->where('status', 'finished')
        ->firstOrFail();
}

/** A finished knockout fixture (tie_id set) belonging to the given tournament. */
function playedKnockoutFixture(int $tournamentId): Fixture
{
    return Fixture::where('tournament_id', $tournamentId)
        ->whereNotNull('tie_id')
        ->where('status', 'finished')
        ->firstOrFail();
}

beforeEach(fn () => $this->seed(TournamentDemoSeeder::class));

test('demo login provisions an isolated sandbox scoped to the session token', function () {
    $body = $this->postJson('/api/login', ['email' => 'demo@bracket.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonStructure(['user', 'token', 'sandbox_tournament_id'])
        ->json();

    $sandbox = Tournament::findOrFail($body['sandbox_tournament_id']);
    $template = Tournament::where('is_demo_template', true)->firstOrFail();

    expect($sandbox->is_demo_template)->toBeFalse()
        ->and($sandbox->demo_token_id)->not->toBeNull()
        ->and($sandbox->template_id)->toBe($template->id)
        ->and($sandbox->demo_expires_at)->not->toBeNull()
        ->and($sandbox->id)->not->toBe($template->id);
});

test('each demo login gets its own sandbox', function () {
    $a = demoLogin($this);
    $b = demoLogin($this);

    expect($a['sandbox'])->not->toBe($b['sandbox']);
});

test('a non-demo login gets no sandbox', function () {
    $user = User::factory()->create(['password' => Hash::make('secret123')]);

    $body = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])
        ->assertOk()
        ->json();

    expect($body)->not->toHaveKey('sandbox_tournament_id');
});

test('the demo template is read-only for everyone', function () {
    $demo = demoLogin($this);
    $template = Tournament::where('is_demo_template', true)->firstOrFail();
    $fixture = playedGroupFixture($template->id);

    $this->withToken($demo['token'])
        ->putJson("/api/matches/{$fixture->id}/result", [
            'home_score' => 5,
            'away_score' => 0,
            'expected_version' => $fixture->version,
        ])
        ->assertForbidden();
});

test('a session can edit its own sandbox', function () {
    $a = demoLogin($this);
    $fixture = playedGroupFixture($a['sandbox']);

    $this->withToken($a['token'])
        ->putJson("/api/matches/{$fixture->id}/result", [
            'home_score' => 4,
            'away_score' => 2,
            'expected_version' => $fixture->version,
        ])
        ->assertOk();
});

test('a session can edit its own sandbox knockout result', function () {
    $a = demoLogin($this);
    $fixture = playedKnockoutFixture($a['sandbox']);

    $this->withToken($a['token'])
        ->putJson("/api/matches/{$fixture->id}/result", [
            'home_score' => 3,
            'away_score' => 1,
            'expected_version' => $fixture->version,
        ])
        ->assertOk();
});

test('an expired sandbox is refused with a demo_expired reason', function () {
    $a = demoLogin($this);
    Tournament::where('id', $a['sandbox'])->update(['demo_expires_at' => now()->subHour()]);
    $fixture = playedGroupFixture($a['sandbox']);

    $this->withToken($a['token'])
        ->putJson("/api/matches/{$fixture->id}/result", [
            'home_score' => 1,
            'away_score' => 0,
            'expected_version' => $fixture->version,
        ])
        ->assertForbidden()
        ->assertJsonPath('reason', 'demo_expired');
});

test('a session cannot edit another session\'s sandbox', function () {
    $a = demoLogin($this);
    $b = demoLogin($this);

    $fixtureA = playedGroupFixture($a['sandbox']);

    $this->withToken($b['token'])
        ->putJson("/api/matches/{$fixtureA->id}/result", [
            'home_score' => 1,
            'away_score' => 0,
            'expected_version' => $fixtureA->version,
        ])
        ->assertForbidden();
});

test('the demo tournaments list shows only this session\'s sandbox', function () {
    $a = demoLogin($this);
    demoLogin($this);

    $data = $this->withToken($a['token'])->getJson('/api/tournaments')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($a['sandbox']);
});

test('reset drops the current sandbox and provisions a fresh one', function () {
    $demo = demoLogin($this);
    $old = $demo['sandbox'];

    $new = $this->withToken($demo['token'])->postJson('/api/demo/reset')
        ->assertOk()
        ->json('sandbox_tournament_id');

    expect($new)->not->toBe($old)
        ->and(Tournament::find($old))->toBeNull()
        ->and(Tournament::find($new))->not->toBeNull();
});

test('non-demo users cannot reset the demo sandbox', function () {
    $user = User::factory()->create(['password' => Hash::make('secret123')]);
    $token = $user->createToken('api')->plainTextToken;

    $this->withToken($token)->postJson('/api/demo/reset')->assertForbidden();
});

test('prune removes expired sandboxes but keeps fresh ones and the template', function () {
    $expired = demoLogin($this);
    Tournament::where('id', $expired['sandbox'])->update(['demo_expires_at' => now()->subHour()]);

    $fresh = demoLogin($this);

    $this->artisan('demo:prune-sandboxes')->assertSuccessful();

    expect(Tournament::find($expired['sandbox']))->toBeNull()
        ->and(Tournament::find($fresh['sandbox']))->not->toBeNull()
        ->and(Tournament::where('is_demo_template', true)->exists())->toBeTrue();
});
