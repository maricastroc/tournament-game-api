<?php

declare(strict_types=1);

use App\Actions\Tournament\ConfirmMatchResult;
use App\Exceptions\StaleResultException;
use App\Models\Fixture;
use App\Models\Group;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Semeia o Grupo A do mock: 4 times e as 4 partidas, todas ainda 'scheduled'.
 *
 * @return array{group: Group, teams: array<string,Team>, fixtures: array<int,Fixture>}
 */
function seedGroupA(): array
{
    $tournament = Tournament::create(['name' => 'Copa Atlas 2026']);
    $stage = Stage::create(['tournament_id' => $tournament->id, 'type' => 'group', 'name' => 'Fase de grupos']);
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

    $fixtures = [
        1 => $make($teams['Brasil'], $teams['Marrocos']),
        2 => $make($teams['Croácia'], $teams['Japão']),
        3 => $make($teams['Brasil'], $teams['Croácia']),
        4 => $make($teams['Japão'], $teams['Marrocos']),
    ];

    return ['group' => $group, 'teams' => $teams, 'fixtures' => $fixtures];
}

test('confirma resultados e recalcula a classificação do grupo', function () {
    ['fixtures' => $f] = seedGroupA();
    $action = new ConfirmMatchResult;

    $action->handle($f[1], 2, 0, 0); // Brasil 2-0 Marrocos
    $action->handle($f[2], 1, 1, 0); // Croácia 1-1 Japão
    $action->handle($f[3], 1, 1, 0); // Brasil 1-1 Croácia
    $table = $action->handle($f[4], 2, 1, 0); // Japão 2-1 Marrocos

    $names = array_map(fn ($s) => $s->team->name, $table);
    expect($names)->toBe(['Brasil', 'Japão', 'Croácia', 'Marrocos']); // Brasil 1º por saldo sobre o Japão

    expect($table[0])->qualified->toBeTrue()
        ->and($table[1])->qualified->toBeTrue()
        ->and($table[2])->qualified->toBeFalse();
});

test('grava o resultado e incrementa a versão (lock otimista)', function () {
    ['fixtures' => $f] = seedGroupA();
    (new ConfirmMatchResult)->handle($f[1], 2, 0, 0);

    $fresh = Fixture::find($f[1]->id);
    expect($fresh->status)->toBe('finished')
        ->and($fresh->home_score)->toBe(2)
        ->and($fresh->version)->toBe(1);
});

test('rejeita edição concorrente com versão desatualizada', function () {
    ['fixtures' => $f] = seedGroupA();
    $action = new ConfirmMatchResult;

    // Primeira gravação leva a versão de 0 -> 1.
    $action->handle($f[1], 2, 0, 0);

    // Segunda pessoa ainda achava que a versão era 0 -> conflito.
    expect(fn () => $action->handle($f[1], 3, 0, 0))
        ->toThrow(StaleResultException::class);

    // O placar da primeira gravação permanece intacto.
    expect(Fixture::find($f[1]->id)->home_score)->toBe(2);
});
