<?php

declare(strict_types=1);

use App\Models\Fixture;
use App\Models\Group;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tie;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Semeia um torneio com grupos decididos (sementes A1..B2) e um mata-mata de
 * duas semifinais alimentando uma final. As partidas do mata-mata ficam 'scheduled'.
 */
function seedKnockout(User $owner): array
{
    $tournament = Tournament::create(['user_id' => $owner->id, 'name' => 'Copa Atlas 2026']);

    $teams = [];
    foreach (['T1', 'T2', 'T3', 'T4'] as $name) {
        $teams[$name] = Team::create(['tournament_id' => $tournament->id, 'name' => $name]);
    }

    // Fase de grupos: T1>T2 e T3>T4  =>  A1=T1, A2=T2, B1=T3, B2=T4
    $groupStage = Stage::create(['tournament_id' => $tournament->id, 'type' => 'group', 'name' => 'Grupos', 'position' => 1]);
    $groupA = Group::create(['stage_id' => $groupStage->id, 'name' => 'A']);
    $groupB = Group::create(['stage_id' => $groupStage->id, 'name' => 'B']);
    $groupA->teams()->attach([$teams['T1']->id, $teams['T2']->id]);
    $groupB->teams()->attach([$teams['T3']->id, $teams['T4']->id]);

    Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $groupStage->id, 'group_id' => $groupA->id,
        'home_team_id' => $teams['T1']->id, 'away_team_id' => $teams['T2']->id, 'home_score' => 1, 'away_score' => 0, 'status' => 'finished']);
    Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $groupStage->id, 'group_id' => $groupB->id,
        'home_team_id' => $teams['T3']->id, 'away_team_id' => $teams['T4']->id, 'home_score' => 1, 'away_score' => 0, 'status' => 'finished']);

    // Mata-mata: SF1 (A1 x B2), SF2 (B1 x A2), Final (vencedor SF1 x vencedor SF2)
    $knockout = Stage::create(['tournament_id' => $tournament->id, 'type' => 'knockout', 'name' => 'Mata-mata', 'position' => 2]);
    $sf1 = Tie::create(['stage_id' => $knockout->id, 'round' => 1, 'slot' => 1, 'home_source' => 'seed:A1', 'away_source' => 'seed:B2']);
    $sf2 = Tie::create(['stage_id' => $knockout->id, 'round' => 1, 'slot' => 2, 'home_source' => 'seed:B1', 'away_source' => 'seed:A2']);
    $final = Tie::create(['stage_id' => $knockout->id, 'round' => 2, 'slot' => 1, 'home_source' => "winner:{$sf1->id}", 'away_source' => "winner:{$sf2->id}"]);

    $fx1 = Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $knockout->id, 'tie_id' => $sf1->id, 'status' => 'scheduled']);
    $fx2 = Fixture::create(['tournament_id' => $tournament->id, 'stage_id' => $knockout->id, 'tie_id' => $sf2->id, 'status' => 'scheduled']);

    return compact('knockout', 'teams', 'sf1', 'final', 'fx1', 'fx2');
}

test('o chaveamento é público e deriva os participantes das sementes', function () {
    ['knockout' => $knockout] = seedKnockout(User::factory()->create());

    $this->getJson("/api/stages/{$knockout->id}/bracket")
        ->assertOk()
        ->assertJsonPath('data.ties.0.home.name', 'T1')   // SF1 home = A1 = T1
        ->assertJsonPath('data.ties.0.away.name', 'T4')   // SF1 away = B2 = T4
        ->assertJsonPath('data.ties.0.status', 'ready')
        ->assertJsonPath('data.ties.2.status', 'pending') // Final: participantes ainda desconhecidos
        ->assertJsonPath('data.champion', null);
});

test('o dono confirma resultado do mata-mata e o vencedor avança para a final', function () {
    $owner = User::factory()->create();
    ['fx1' => $fx1] = seedKnockout($owner);
    Sanctum::actingAs($owner);

    $this->putJson("/api/matches/{$fx1->id}/result", [
        'home_score' => 2, 'away_score' => 1, 'expected_version' => 0,
    ])
        ->assertOk()
        ->assertJsonPath('data.ties.0.winner.name', 'T1')  // SF1 decidida
        ->assertJsonPath('data.ties.2.home.name', 'T1')    // T1 avançou para a final
        ->assertJsonPath('data.ties.2.away', null)         // outro finalista ainda indefinido
        ->assertJsonPath('data.champion', null);

    expect(Fixture::find($fx1->id)->version)->toBe(1);
});

test('vitória por pênaltis no mata-mata faz o time avançar', function () {
    $owner = User::factory()->create();
    ['fx1' => $fx1] = seedKnockout($owner);
    Sanctum::actingAs($owner);

    $this->putJson("/api/matches/{$fx1->id}/result", [
        'home_score' => 1, 'away_score' => 1, 'expected_version' => 0,
        'home_penalties' => 4, 'away_penalties' => 3,
    ])
        ->assertOk()
        ->assertJsonPath('data.ties.0.winner.name', 'T1')
        ->assertJsonPath('data.ties.0.decided_by_penalties', true)
        ->assertJsonPath('data.ties.2.home.name', 'T1');
});
