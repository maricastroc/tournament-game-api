<?php

declare(strict_types=1);

// Destino no projeto: tests/Unit/Domain/Tournament/GroupTableTest.php
// Teste puro: NÃO usa RefreshDatabase nem toca o banco — roda em milissegundos.

use App\Domain\Tournament\Input\MatchResult;
use App\Domain\Tournament\Input\TeamRef;
use App\Domain\Tournament\Standings\Criterion;
use App\Domain\Tournament\Standings\GroupTable;
use App\Domain\Tournament\Standings\TiebreakRules;

/** @param array<int,string> $names @return TeamRef[] */
function teams(array $names): array
{
    $out = [];
    foreach ($names as $id => $name) {
        $out[] = new TeamRef($id, $name);
    }
    return $out;
}

/** @param Standing[] $table @return array<int,int> id => posição */
function positionsOf(array $table): array
{
    return collect($table)->mapWithKeys(fn ($s) => [$s->team->id => $s->position])->all();
}

// ---------------------------------------------------------------------------
// Cenários nomeados
// ---------------------------------------------------------------------------

test('ordena por pontos e marca os classificados', function () {
    $table = GroupTable::compute(
        teams([1 => 'Brasil', 2 => 'Croácia', 3 => 'Marrocos', 4 => 'Japão']),
        [
            new MatchResult(1, 3, 2, 0),
            new MatchResult(2, 4, 1, 1),
            new MatchResult(1, 2, 1, 1),
            new MatchResult(4, 3, 2, 1),
        ],
        TiebreakRules::fifa(),
    );

    $pos = positionsOf($table);

    expect($pos[1])->toBe(1)   // Brasil — 4 pts, saldo +2
        ->and($pos[4])->toBe(2) // Japão — 4 pts, saldo +1
        ->and($pos[2])->toBe(3) // Croácia — 2 pts
        ->and($pos[3])->toBe(4) // Marrocos — 0 pts
        ->and($table[0]->qualified)->toBeTrue()
        ->and($table[2]->qualified)->toBeFalse();
});

test('empate em pontos é resolvido pelo saldo de gols', function () {
    $pos = positionsOf(GroupTable::compute(
        teams([1 => 'A', 2 => 'B', 3 => 'C']),
        [new MatchResult(1, 3, 3, 0), new MatchResult(2, 3, 1, 0), new MatchResult(1, 2, 0, 0)],
        TiebreakRules::fifa(),
    ));

    expect($pos[1])->toBe(1)->and($pos[2])->toBe(2);
});

test('empate em pontos e saldo é resolvido por gols pró', function () {
    $pos = positionsOf(GroupTable::compute(
        teams([1 => 'A', 2 => 'B', 3 => 'C']),
        [new MatchResult(1, 3, 2, 1), new MatchResult(2, 3, 1, 0), new MatchResult(1, 2, 1, 1)],
        TiebreakRules::fifa(),
    ));

    expect($pos[1])->toBe(1)->and($pos[2])->toBe(2);
});

test('confronto direto desempata contra a ordem de entrada', function () {
    // Entrada [B, A, C]: sem confronto direto, B ficaria à frente de A.
    // A venceu A×B, então deve subir.
    $pos = positionsOf(GroupTable::compute(
        teams([2 => 'B', 1 => 'A', 3 => 'C']),
        [new MatchResult(1, 2, 2, 1), new MatchResult(1, 3, 0, 1), new MatchResult(2, 3, 1, 0)],
        TiebreakRules::fifa(),
    ));

    expect($pos[1])->toBe(1)->and($pos[2])->toBe(2)->and($pos[3])->toBe(3);
});

test('ciclo irredutível cai na ordem determinística de entrada', function () {
    $matches = [new MatchResult(1, 2, 1, 0), new MatchResult(2, 3, 1, 0), new MatchResult(3, 1, 1, 0)];

    $order = fn (array $t) => collect(GroupTable::compute($t, $matches, TiebreakRules::fifa()))
        ->map(fn ($s) => $s->team->id)->all();

    expect($order(teams([1 => 'A', 2 => 'B', 3 => 'C'])))->toBe([1, 2, 3])
        ->and($order(teams([3 => 'C', 2 => 'B', 1 => 'A'])))->toBe([3, 2, 1]);
});

// ---------------------------------------------------------------------------
// Property tests — invariantes sobre grupos aleatórios (seed fixa = reprodutível)
// ---------------------------------------------------------------------------

test('invariantes valem para qualquer grupo (property-based)', function () {
    mt_srand(20260709); // determinístico: mesma suíte, mesmos casos

    for ($iteration = 0; $iteration < 300; $iteration++) {
        $n = mt_rand(3, 5);
        $roster = teams(array_combine(range(1, $n), array_map(fn ($i) => "T{$i}", range(1, $n))));

        // round-robin completo com placares aleatórios
        $matches = [];
        for ($h = 1; $h <= $n; $h++) {
            for ($a = $h + 1; $a <= $n; $a++) {
                $matches[] = new MatchResult($h, $a, mt_rand(0, 4), mt_rand(0, 4));
            }
        }

        $table = GroupTable::compute($roster, $matches, TiebreakRules::fifa());

        // 1. conservação de pontos: cada partida distribui 3 (vitória) ou 2 (empate)
        $expectedPts = array_sum(array_map(fn ($m) => $m->homeScore === $m->awayScore ? 2 : 3, $matches));
        expect(array_sum(array_map(fn ($s) => $s->points, $table)))->toBe($expectedPts);

        // 2. conservação de jogos e de gols
        expect(array_sum(array_map(fn ($s) => $s->played, $table)))->toBe(2 * count($matches));
        expect(array_sum(array_map(fn ($s) => $s->goalsFor, $table)))
            ->toBe(array_sum(array_map(fn ($s) => $s->goalsAgainst, $table)));

        // 3. posições são uma permutação de 1..n
        expect(array_map(fn ($s) => $s->position, $table))->toBe(range(1, $n));

        // 4. pontos são não-crescentes ao descer a tabela (o 1º critério nunca é violado)
        for ($i = 1; $i < $n; $i++) {
            expect($table[$i]->points)->toBeLessThanOrEqual($table[$i - 1]->points);
        }

        // 5. determinismo: recomputar dá exatamente a mesma ordem
        $again = GroupTable::compute($roster, $matches, TiebreakRules::fifa());
        expect(array_map(fn ($s) => $s->team->id, $again))
            ->toBe(array_map(fn ($s) => $s->team->id, $table));
    }
});
