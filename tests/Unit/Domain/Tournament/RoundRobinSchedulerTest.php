<?php

declare(strict_types=1);

use App\Domain\Tournament\Fixture\RoundRobinScheduler;

/** @param list<array{home:int, away:int}> $fixtures */
function pairKeys(array $fixtures): array
{
    return array_map(function (array $f) {
        $pair = [$f['home'], $f['away']];
        sort($pair);

        return implode('-', $pair);
    }, $fixtures);
}

test('generates all ties of a single round-robin, each pair once', function () {
    $fixtures = RoundRobinScheduler::schedule([10, 20, 30, 40]);

    expect($fixtures)->toHaveCount(6);

    $keys = pairKeys($fixtures);
    expect($keys)->toEqualCanonicalizing(['10-20', '10-30', '10-40', '20-30', '20-40', '30-40']);

    expect(array_unique($keys))->toHaveCount(6);
});

test('handles an odd number of teams (bye) without generating a phantom match', function () {
    $fixtures = RoundRobinScheduler::schedule([1, 2, 3]);

    expect($fixtures)->toHaveCount(3);
    expect(pairKeys($fixtures))->toEqualCanonicalizing(['1-2', '1-3', '2-3']);

    foreach ($fixtures as $f) {
        expect($f['home'])->toBeGreaterThan(0)
            ->and($f['away'])->toBeGreaterThan(0);
    }
});

test('fewer than two teams generates no match', function () {
    expect(RoundRobinScheduler::schedule([]))->toBe([]);
    expect(RoundRobinScheduler::schedule([7]))->toBe([]);
});

test('each team plays every other exactly once', function () {
    $ids = [1, 2, 3, 4, 5, 6];
    $fixtures = RoundRobinScheduler::schedule($ids);

    $appearances = array_fill_keys($ids, 0);
    foreach ($fixtures as $f) {
        $appearances[$f['home']]++;
        $appearances[$f['away']]++;
    }

    foreach ($appearances as $count) {
        expect($count)->toBe(5);
    }
    expect($fixtures)->toHaveCount(15);
});
