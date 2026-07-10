<?php

declare(strict_types=1);

use App\Actions\Tournament\ConfirmMatchResult;
use App\Models\Fixture;
use App\Models\Group;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Seeds Group A owned by $owner, with the 4 matches still 'scheduled'.
 *
 * @return array{group: Group, fixtures: array<int,Fixture>}
 */
function seedOwnedTournament(User $owner): array
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
        'group' => $group,
        'fixtures' => [
            1 => $make($teams['Brasil'], $teams['Marrocos']),
            2 => $make($teams['Croácia'], $teams['Japão']),
            3 => $make($teams['Brasil'], $teams['Croácia']),
            4 => $make($teams['Japão'], $teams['Marrocos']),
        ],
    ];
}

test('registration and login issue a token', function () {
    $this->postJson('/api/register', [
        'name' => 'Mari',
        'email' => 'mari@example.com',
        'password' => 'password123',
    ])->assertCreated()->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

    $this->postJson('/api/login', [
        'email' => 'mari@example.com',
        'password' => 'password123',
    ])->assertOk()->assertJsonStructure(['token']);
});

test('a group standings is public', function () {
    ['group' => $group, 'fixtures' => $f] = seedOwnedTournament(User::factory()->create());
    $action = new ConfirmMatchResult;
    $action->handle($f[1], 2, 0, 0);
    $action->handle($f[2], 1, 1, 0);
    $action->handle($f[3], 1, 1, 0);
    $action->handle($f[4], 2, 1, 0);

    $this->getJson("/api/groups/{$group->id}/standings")
        ->assertOk()
        ->assertJsonPath('data.0.team.name', 'Brasil')
        ->assertJsonPath('data.0.position', 1)
        ->assertJsonPath('data.0.qualified', true)
        ->assertJsonPath('data.1.team.name', 'Japão')
        ->assertJsonPath('data.3.team.name', 'Marrocos');
});

test('submitting a result requires authentication', function () {
    ['fixtures' => $f] = seedOwnedTournament(User::factory()->create());

    $this->putJson("/api/matches/{$f[1]->id}/result", [
        'home_score' => 2, 'away_score' => 0, 'expected_version' => 0,
    ])->assertUnauthorized();
});

test('a non-owner of the tournament cannot submit a result', function () {
    ['fixtures' => $f] = seedOwnedTournament(User::factory()->create());
    Sanctum::actingAs(User::factory()->create());

    $this->putJson("/api/matches/{$f[1]->id}/result", [
        'home_score' => 2, 'away_score' => 0, 'expected_version' => 0,
    ])->assertForbidden();
});

test('an invalid score returns 422', function () {
    $owner = User::factory()->create();
    ['fixtures' => $f] = seedOwnedTournament($owner);
    Sanctum::actingAs($owner);

    $this->putJson("/api/matches/{$f[1]->id}/result", [
        'home_score' => -1, 'away_score' => 0, 'expected_version' => 0,
    ])->assertStatus(422)->assertJsonValidationErrors('home_score');
});

test('the owner submits the result and receives the recalculated standings', function () {
    $owner = User::factory()->create();
    ['fixtures' => $f] = seedOwnedTournament($owner);
    Sanctum::actingAs($owner);

    $this->putJson("/api/matches/{$f[1]->id}/result", [
        'home_score' => 2, 'away_score' => 0, 'expected_version' => 0,
    ])->assertOk()->assertJsonPath('data.0.team.name', 'Brasil');

    expect(Fixture::find($f[1]->id)->version)->toBe(1);
});

test('a concurrent edit with a stale version returns 409', function () {
    $owner = User::factory()->create();
    ['fixtures' => $f] = seedOwnedTournament($owner);
    Sanctum::actingAs($owner);

    $payload = ['home_score' => 2, 'away_score' => 0, 'expected_version' => 0];
    $this->putJson("/api/matches/{$f[1]->id}/result", $payload)->assertOk();

    $this->putJson("/api/matches/{$f[1]->id}/result", $payload)
        ->assertStatus(409)
        ->assertJsonPath('expected_version', 0);
});
