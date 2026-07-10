<?php

declare(strict_types=1);

use App\Domain\Tournament\Input\MatchResult;
use App\Domain\Tournament\Input\TeamRef;
use App\Domain\Tournament\Standings\GroupTable;
use App\Domain\Tournament\Standings\Standing;
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

/** @param Standing[] $table @return array<int,int> id => position */
function positionsOf(array $table): array
{
    return collect($table)->mapWithKeys(fn ($s) => [$s->team->id => $s->position])->all();
}

test('orders by points and marks the qualified teams', function () {
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

    expect($pos[1])->toBe(1)
        ->and($pos[4])->toBe(2)
        ->and($pos[2])->toBe(3)
        ->and($pos[3])->toBe(4)
        ->and($table[0]->qualified)->toBeTrue()
        ->and($table[2]->qualified)->toBeFalse();
});

test('a tie on points is resolved by goal difference', function () {
    $pos = positionsOf(GroupTable::compute(
        teams([1 => 'A', 2 => 'B', 3 => 'C']),
        [new MatchResult(1, 3, 3, 0), new MatchResult(2, 3, 1, 0), new MatchResult(1, 2, 0, 0)],
        TiebreakRules::fifa(),
    ));

    expect($pos[1])->toBe(1)->and($pos[2])->toBe(2);
});

test('a tie on points and goal difference is resolved by goals for', function () {
    $pos = positionsOf(GroupTable::compute(
        teams([1 => 'A', 2 => 'B', 3 => 'C']),
        [new MatchResult(1, 3, 2, 1), new MatchResult(2, 3, 1, 0), new MatchResult(1, 2, 1, 1)],
        TiebreakRules::fifa(),
    ));

    expect($pos[1])->toBe(1)->and($pos[2])->toBe(2);
});

test('head-to-head breaks the tie against the entry order', function () {
    $pos = positionsOf(GroupTable::compute(
        teams([2 => 'B', 1 => 'A', 3 => 'C']),
        [new MatchResult(1, 2, 2, 1), new MatchResult(1, 3, 0, 1), new MatchResult(2, 3, 1, 0)],
        TiebreakRules::fifa(),
    ));

    expect($pos[1])->toBe(1)->and($pos[2])->toBe(2)->and($pos[3])->toBe(3);
});

test('an irreducible cycle falls back to the deterministic entry order', function () {
    $matches = [new MatchResult(1, 2, 1, 0), new MatchResult(2, 3, 1, 0), new MatchResult(3, 1, 1, 0)];

    $order = fn (array $t) => collect(GroupTable::compute($t, $matches, TiebreakRules::fifa()))
        ->map(fn ($s) => $s->team->id)->all();

    expect($order(teams([1 => 'A', 2 => 'B', 3 => 'C'])))->toBe([1, 2, 3])
        ->and($order(teams([3 => 'C', 2 => 'B', 1 => 'A'])))->toBe([3, 2, 1]);
});

test('invariants hold for any group (property-based)', function () {
    mt_srand(20260709);

    for ($iteration = 0; $iteration < 300; $iteration++) {
        $n = mt_rand(3, 5);
        $roster = teams(array_combine(range(1, $n), array_map(fn ($i) => "T{$i}", range(1, $n))));

        $matches = [];
        for ($h = 1; $h <= $n; $h++) {
            for ($a = $h + 1; $a <= $n; $a++) {
                $matches[] = new MatchResult($h, $a, mt_rand(0, 4), mt_rand(0, 4));
            }
        }

        $table = GroupTable::compute($roster, $matches, TiebreakRules::fifa());

        $expectedPts = array_sum(array_map(fn ($m) => $m->homeScore === $m->awayScore ? 2 : 3, $matches));
        expect(array_sum(array_map(fn ($s) => $s->points, $table)))->toBe($expectedPts);

        expect(array_sum(array_map(fn ($s) => $s->played, $table)))->toBe(2 * count($matches));
        expect(array_sum(array_map(fn ($s) => $s->goalsFor, $table)))
            ->toBe(array_sum(array_map(fn ($s) => $s->goalsAgainst, $table)));

        expect(array_map(fn ($s) => $s->position, $table))->toBe(range(1, $n));

        for ($i = 1; $i < $n; $i++) {
            expect($table[$i]->points)->toBeLessThanOrEqual($table[$i - 1]->points);
        }

        $again = GroupTable::compute($roster, $matches, TiebreakRules::fifa());
        expect(array_map(fn ($s) => $s->team->id, $again))
            ->toBe(array_map(fn ($s) => $s->team->id, $table));
    }
});
