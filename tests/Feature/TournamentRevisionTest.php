<?php

declare(strict_types=1);

use App\Events\TournamentUpdated;
use App\Models\Fixture;
use App\Models\Group;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tie;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A group-stage tournament owned by $owner with two scheduled matches. Kept local so this
 * file runs standalone (mirrors the shared fixtures in TournamentApiTest).
 *
 * @return array{tournament: Tournament, fixtures: array<int,Fixture>}
 */
function seedRevisionGroup(User $owner): array
{
    $tournament = Tournament::create(['user_id' => $owner->id, 'name' => 'Atlas Cup 2026']);
    $stage = Stage::create(['tournament_id' => $tournament->id, 'type' => 'group', 'name' => 'Group stage']);
    $group = Group::create(['stage_id' => $stage->id, 'name' => 'A', 'qualify_count' => 2]);

    $teams = [];
    foreach (['Brasil', 'Croácia', 'Marrocos', 'Japão'] as $name) {
        $teams[$name] = Team::create(['tournament_id' => $tournament->id, 'name' => $name]);
    }
    $group->teams()->attach(collect($teams)->pluck('id'));

    $make = fn (Team $home, Team $away) => Fixture::create([
        'tournament_id' => $tournament->id,
        'stage_id' => $stage->id,
        'group_id' => $group->id,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'scheduled',
    ]);

    return [
        'tournament' => $tournament,
        'fixtures' => [
            1 => $make($teams['Brasil'], $teams['Marrocos']),
            2 => $make($teams['Croácia'], $teams['Japão']),
        ],
    ];
}

/**
 * A knockout tournament owned by $owner with one playable semi-final fixture.
 *
 * @return array{tournament: Tournament, fixture: Fixture}
 */
function seedRevisionKnockout(User $owner): array
{
    $tournament = Tournament::create(['user_id' => $owner->id, 'name' => 'Atlas Cup 2026']);

    $teams = [];
    foreach (['T1', 'T2', 'T3', 'T4'] as $name) {
        $teams[$name] = Team::create(['tournament_id' => $tournament->id, 'name' => $name]);
    }

    $groupStage = Stage::create(['tournament_id' => $tournament->id, 'type' => 'group', 'name' => 'Groups', 'position' => 1]);
    $groupA = Group::create(['stage_id' => $groupStage->id, 'name' => 'A']);
    $groupB = Group::create(['stage_id' => $groupStage->id, 'name' => 'B']);
    $groupA->teams()->attach([$teams['T1']->id, $teams['T2']->id]);
    $groupB->teams()->attach([$teams['T3']->id, $teams['T4']->id]);

    Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $groupStage->id, 'group_id' => $groupA->id,
        'home_team_id' => $teams['T1']->id, 'away_team_id' => $teams['T2']->id, 'home_score' => 1, 'away_score' => 0, 'status' => 'finished']);
    Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $groupStage->id, 'group_id' => $groupB->id,
        'home_team_id' => $teams['T3']->id, 'away_team_id' => $teams['T4']->id, 'home_score' => 1, 'away_score' => 0, 'status' => 'finished']);

    $knockout = Stage::create(['tournament_id' => $tournament->id, 'type' => 'knockout', 'name' => 'Knockout', 'position' => 2]);
    $sf1 = Tie::create(['stage_id' => $knockout->id, 'round' => 1, 'slot' => 1, 'home_source' => 'seed:A1', 'away_source' => 'seed:B2']);
    $sf2 = Tie::create(['stage_id' => $knockout->id, 'round' => 1, 'slot' => 2, 'home_source' => 'seed:B1', 'away_source' => 'seed:A2']);
    Tie::create(['stage_id' => $knockout->id, 'round' => 2, 'slot' => 1, 'home_source' => "winner:{$sf1->id}", 'away_source' => "winner:{$sf2->id}"]);

    $fixture = Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $knockout->id, 'tie_id' => $sf1->id, 'status' => 'scheduled']);

    return ['tournament' => $tournament, 'fixture' => $fixture];
}

test('a new tournament starts at revision 0', function () {
    ['tournament' => $t] = seedRevisionGroup(User::factory()->create());

    expect($t->fresh()->revision)->toBe(0);
});

test('a committed group result bumps the tournament revision', function () {
    $owner = User::factory()->create();
    ['tournament' => $t, 'fixtures' => $f] = seedRevisionGroup($owner);
    Sanctum::actingAs($owner);

    $this->putJson("/api/matches/{$f[1]->id}/result", [
        'home_score' => 2, 'away_score' => 0, 'expected_version' => 0,
    ])->assertOk();
    expect($t->fresh()->revision)->toBe(1);

    $this->putJson("/api/matches/{$f[2]->id}/result", [
        'home_score' => 1, 'away_score' => 1, 'expected_version' => 0,
    ])->assertOk();
    expect($t->fresh()->revision)->toBe(2);
});

test('a committed knockout result bumps the tournament revision', function () {
    $owner = User::factory()->create();
    ['tournament' => $t, 'fixture' => $fx] = seedRevisionKnockout($owner);
    Sanctum::actingAs($owner);

    $this->putJson("/api/matches/{$fx->id}/result", [
        'home_score' => 2, 'away_score' => 1, 'expected_version' => 0,
    ])->assertOk();

    expect($t->fresh()->revision)->toBe(1);
});

test('a stale 409 does not bump the revision', function () {
    $owner = User::factory()->create();
    ['tournament' => $t, 'fixtures' => $f] = seedRevisionGroup($owner);
    Sanctum::actingAs($owner);

    $payload = ['home_score' => 2, 'away_score' => 0, 'expected_version' => 0];
    $this->putJson("/api/matches/{$f[1]->id}/result", $payload)->assertOk();
    expect($t->fresh()->revision)->toBe(1);

    $this->putJson("/api/matches/{$f[1]->id}/result", $payload)->assertStatus(409);
    expect($t->fresh()->revision)->toBe(1);
});

test('a forbidden (non-owner) save does not bump the revision', function () {
    ['tournament' => $t, 'fixtures' => $f] = seedRevisionGroup(User::factory()->create());
    Sanctum::actingAs(User::factory()->create());

    $this->putJson("/api/matches/{$f[1]->id}/result", [
        'home_score' => 2, 'away_score' => 0, 'expected_version' => 0,
    ])->assertForbidden();

    expect($t->fresh()->revision)->toBe(0);
});

test('an invalid (422) save does not bump the revision', function () {
    $owner = User::factory()->create();
    ['tournament' => $t, 'fixtures' => $f] = seedRevisionGroup($owner);
    Sanctum::actingAs($owner);

    $this->putJson("/api/matches/{$f[1]->id}/result", [
        'home_score' => -1, 'away_score' => 0, 'expected_version' => 0,
    ])->assertStatus(422);

    expect($t->fresh()->revision)->toBe(0);
});

test('a save bumps only its own tournament revision', function () {
    $owner = User::factory()->create();
    ['tournament' => $a, 'fixtures' => $fa] = seedRevisionGroup($owner);
    ['tournament' => $b] = seedRevisionGroup($owner);
    Sanctum::actingAs($owner);

    $this->putJson("/api/matches/{$fa[1]->id}/result", [
        'home_score' => 2, 'away_score' => 0, 'expected_version' => 0,
    ])->assertOk();

    expect($a->fresh()->revision)->toBe(1)
        ->and($b->fresh()->revision)->toBe(0);
});

test('a committed save dispatches TournamentUpdated with the new revision', function () {
    Event::fake([TournamentUpdated::class]);

    $owner = User::factory()->create();
    ['tournament' => $t, 'fixtures' => $f] = seedRevisionGroup($owner);
    Sanctum::actingAs($owner);

    $this->putJson("/api/matches/{$f[1]->id}/result", [
        'home_score' => 2, 'away_score' => 0, 'expected_version' => 0,
    ])->assertOk();

    Event::assertDispatched(TournamentUpdated::class, fn (TournamentUpdated $e) => $e->tournamentId === (int) $t->id
        && $e->revision === 1
        && $e->type === 'result');
});

test('a rejected (422) save does not dispatch TournamentUpdated', function () {
    Event::fake([TournamentUpdated::class]);

    $owner = User::factory()->create();
    ['fixtures' => $f] = seedRevisionGroup($owner);
    Sanctum::actingAs($owner);

    $this->putJson("/api/matches/{$f[1]->id}/result", [
        'home_score' => -1, 'away_score' => 0, 'expected_version' => 0,
    ])->assertStatus(422);

    Event::assertNotDispatched(TournamentUpdated::class);
});

test('a stale (409) save does not add a dispatch', function () {
    Event::fake([TournamentUpdated::class]);

    $owner = User::factory()->create();
    ['fixtures' => $f] = seedRevisionGroup($owner);
    Sanctum::actingAs($owner);

    $payload = ['home_score' => 2, 'away_score' => 0, 'expected_version' => 0];
    $this->putJson("/api/matches/{$f[1]->id}/result", $payload)->assertOk();
    $this->putJson("/api/matches/{$f[1]->id}/result", $payload)->assertStatus(409);

    Event::assertDispatchedTimes(TournamentUpdated::class, 1);
});

test('the tournament stream is public and served as text/event-stream', function () {
    config(['sse.max_seconds' => 0]);
    ['tournament' => $t] = seedRevisionGroup(User::factory()->create());

    $response = $this->get("/api/tournaments/{$t->id}/stream")->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    $response->assertHeader('X-Accel-Buffering', 'no');
});

test('the stream endpoint 404s for an unknown tournament', function () {
    $this->get('/api/tournaments/999999/stream')->assertNotFound();
});
