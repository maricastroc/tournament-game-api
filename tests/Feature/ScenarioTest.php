<?php

declare(strict_types=1);

use App\Models\Fixture;
use App\Models\Group;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tie;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Once T1/T3 win their groups: A1=T1, A2=T2, B1=T3, B2=T4.
 * sf1 = A1 vs B2   sf2 = B1 vs A2   final = winner(sf1) vs winner(sf2)
 *
 * @return array{tournament: Tournament, teams: array<string,Team>, fixtures: array<string,Fixture>, ties: array<string,Tie>}
 */
function seedScenarioTournament(): array
{
    $tournament = Tournament::create(['name' => 'Atlas Cup 2026']);

    $teams = [];
    foreach (['T1', 'T2', 'T3', 'T4'] as $name) {
        $teams[$name] = Team::create(['tournament_id' => $tournament->id, 'name' => $name]);
    }

    $groupStage = Stage::create(['tournament_id' => $tournament->id, 'type' => 'group', 'name' => 'Groups', 'position' => 1]);
    $groupA = Group::create(['stage_id' => $groupStage->id, 'name' => 'A', 'qualify_count' => 2]);
    $groupB = Group::create(['stage_id' => $groupStage->id, 'name' => 'B', 'qualify_count' => 2]);
    $groupA->teams()->attach([$teams['T1']->id, $teams['T2']->id]);
    $groupB->teams()->attach([$teams['T3']->id, $teams['T4']->id]);

    $fixtures = [];
    $fixtures['A'] = Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $groupStage->id, 'group_id' => $groupA->id,
        'home_team_id' => $teams['T1']->id, 'away_team_id' => $teams['T2']->id, 'status' => 'scheduled']);
    $fixtures['B'] = Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $groupStage->id, 'group_id' => $groupB->id,
        'home_team_id' => $teams['T3']->id, 'away_team_id' => $teams['T4']->id, 'status' => 'scheduled']);

    $knockout = Stage::create(['tournament_id' => $tournament->id, 'type' => 'knockout', 'name' => 'Knockout', 'position' => 2]);
    $sf1 = Tie::create(['stage_id' => $knockout->id, 'round' => 1, 'slot' => 1, 'home_source' => 'seed:A1', 'away_source' => 'seed:B2']);
    $sf2 = Tie::create(['stage_id' => $knockout->id, 'round' => 1, 'slot' => 2, 'home_source' => 'seed:B1', 'away_source' => 'seed:A2']);
    $final = Tie::create(['stage_id' => $knockout->id, 'round' => 2, 'slot' => 1, 'home_source' => "winner:{$sf1->id}", 'away_source' => "winner:{$sf2->id}"]);

    $fixtures['sf1'] = Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $knockout->id, 'tie_id' => $sf1->id, 'status' => 'scheduled']);
    $fixtures['sf2'] = Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $knockout->id, 'tie_id' => $sf2->id, 'status' => 'scheduled']);
    $fixtures['final'] = Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $knockout->id, 'tie_id' => $final->id, 'status' => 'scheduled']);

    return ['tournament' => $tournament, 'teams' => $teams, 'fixtures' => $fixtures, 'ties' => ['sf1' => $sf1, 'sf2' => $sf2, 'final' => $final]];
}

test('projects hypothetical group results without persisting anything', function () {
    ['tournament' => $tournament, 'fixtures' => $f] = seedScenarioTournament();

    $this->postJson("/api/tournaments/{$tournament->id}/scenario", [
        'results' => [
            ['fixture_id' => $f['A']->id, 'home_score' => 2, 'away_score' => 0],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.groups.0.standings.0.team.name', 'T1')
        ->assertJsonPath('data.groups.0.standings.0.points', 3)
        ->assertJsonPath('data.groups.0.standings.0.qualified', true);

    $fresh = Fixture::find($f['A']->id);
    expect($fresh->status)->toBe('scheduled')
        ->and($fresh->home_score)->toBeNull()
        ->and($fresh->version)->toBe(0);
});

test('a hypothetical group result cascades into the bracket seeds', function () {
    ['tournament' => $tournament, 'fixtures' => $f] = seedScenarioTournament();

    // T2 beats T1, so T2 wins group A and takes the A1 seed feeding semifinal 1.
    $this->postJson("/api/tournaments/{$tournament->id}/scenario", [
        'results' => [
            ['fixture_id' => $f['A']->id, 'home_score' => 0, 'away_score' => 3],
            ['fixture_id' => $f['B']->id, 'home_score' => 1, 'away_score' => 0],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.bracket.ties.0.home.name', 'T2')
        ->assertJsonPath('data.bracket.ties.0.away.name', 'T4')
        ->assertJsonPath('data.bracket.ties.0.status', 'ready');
});

test('hypothetical results all the way through crown the champion', function () {
    ['tournament' => $tournament, 'fixtures' => $f] = seedScenarioTournament();

    $this->postJson("/api/tournaments/{$tournament->id}/scenario", [
        'results' => [
            ['fixture_id' => $f['A']->id, 'home_score' => 2, 'away_score' => 0],
            ['fixture_id' => $f['B']->id, 'home_score' => 1, 'away_score' => 0],
            ['fixture_id' => $f['sf1']->id, 'home_score' => 1, 'away_score' => 1, 'home_penalties' => 4, 'away_penalties' => 2],
            ['fixture_id' => $f['sf2']->id, 'home_score' => 2, 'away_score' => 0],
            ['fixture_id' => $f['final']->id, 'home_score' => 3, 'away_score' => 1],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.bracket.ties.0.winner.name', 'T1')
        ->assertJsonPath('data.bracket.ties.0.decided_by_penalties', true)
        ->assertJsonPath('data.bracket.champion.name', 'T1');
});

test('an empty scenario returns the current (all-scheduled) projection', function () {
    ['tournament' => $tournament] = seedScenarioTournament();

    $this->postJson("/api/tournaments/{$tournament->id}/scenario", ['results' => []])
        ->assertOk()
        ->assertJsonPath('data.groups.0.standings.0.played', 0)
        ->assertJsonPath('data.bracket.champion', null);
});

test('fixture ids from outside the tournament are ignored, not leaked', function () {
    ['tournament' => $tournament, 'fixtures' => $f] = seedScenarioTournament();

    $this->postJson("/api/tournaments/{$tournament->id}/scenario", [
        'results' => [
            ['fixture_id' => $f['A']->id, 'home_score' => 1, 'away_score' => 0],
            ['fixture_id' => 999999, 'home_score' => 9, 'away_score' => 0],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.groups.0.standings.0.team.name', 'T1')
        ->assertJsonPath('data.groups.0.standings.0.goals_for', 1);
});

test('the scenario endpoint needs no authentication', function () {
    ['tournament' => $tournament] = seedScenarioTournament();

    $this->postJson("/api/tournaments/{$tournament->id}/scenario", ['results' => []])
        ->assertOk();
});
