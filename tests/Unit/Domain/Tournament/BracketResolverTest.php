<?php

declare(strict_types=1);

use App\Domain\Tournament\Bracket\BracketResolver;
use App\Domain\Tournament\Bracket\ResolvedTie;
use App\Domain\Tournament\Bracket\SlotSource;
use App\Domain\Tournament\Bracket\Tie;
use App\Domain\Tournament\Bracket\TieResult;
use App\Domain\Tournament\Input\TeamRef;

/** Standard topology: 4 quarterfinals -> 2 semifinals -> 1 final. */
function knockoutTies(): array
{
    return [
        new Tie(1, 1, SlotSource::seed('A1'), SlotSource::seed('B2')),
        new Tie(2, 1, SlotSource::seed('C1'), SlotSource::seed('D2')),
        new Tie(3, 1, SlotSource::seed('B1'), SlotSource::seed('A2')),
        new Tie(4, 1, SlotSource::seed('D1'), SlotSource::seed('C2')),
        new Tie(5, 2, SlotSource::winnerOf(1), SlotSource::winnerOf(2)),
        new Tie(6, 2, SlotSource::winnerOf(3), SlotSource::winnerOf(4)),
        new Tie(7, 3, SlotSource::winnerOf(5), SlotSource::winnerOf(6)),
    ];
}

function knockoutSeeds(): array
{
    return [
        'A1' => new TeamRef(1, 'Brasil'),
        'B2' => new TeamRef(2, 'França'),
        'C1' => new TeamRef(3, 'Espanha'),
        'D2' => new TeamRef(4, 'Portugal'),
        'B1' => new TeamRef(5, 'Argentina'),
        'A2' => new TeamRef(6, 'Japão'),
        'D1' => new TeamRef(7, 'Itália'),
        'C2' => new TeamRef(8, 'Alemanha'),
    ];
}

function fullResults(): array
{
    return [
        new TieResult(1, 3, 1),
        new TieResult(2, 2, 2, 4, 3),
        new TieResult(3, 1, 0),
        new TieResult(4, 0, 2),
        new TieResult(5, 2, 1),
        new TieResult(6, 1, 1, 5, 4),
        new TieResult(7, 1, 0),
    ];
}

/** @return array<int,ResolvedTie> */
function tiesById(array $resolved): array
{
    return collect($resolved['ties'])->keyBy('id')->all();
}

test('derives participants of the following rounds from the winners', function () {
    $t = tiesById(BracketResolver::resolve(knockoutTies(), fullResults(), knockoutSeeds()));

    expect($t[5]->home->name)->toBe('Brasil')
        ->and($t[5]->away->name)->toBe('Espanha')
        ->and($t[7]->home->name)->toBe('Brasil')
        ->and($t[7]->away->name)->toBe('Argentina');
});

test('resolves ties decided on penalties', function () {
    $t = tiesById(BracketResolver::resolve(knockoutTies(), fullResults(), knockoutSeeds()));

    expect($t[2]->decidedByPenalties)->toBeTrue()
        ->and($t[2]->winner->name)->toBe('Espanha')
        ->and($t[6]->decidedByPenalties)->toBeTrue()
        ->and($t[6]->winner->name)->toBe('Argentina');
});

test('elects the champion as the winner of the final', function () {
    $result = BracketResolver::resolve(knockoutTies(), fullResults(), knockoutSeeds());

    expect($result['champion']->name)->toBe('Brasil');
});

test('propagates "TBD" when a source tie has not finished', function () {
    $partial = array_values(array_filter(fullResults(), fn (TieResult $r) => ! in_array($r->tieId, [6, 7], true)));
    $t = tiesById(BracketResolver::resolve(knockoutTies(), $partial, knockoutSeeds()));

    expect($t[6]->status)->toBe('ready')
        ->and($t[7]->status)->toBe('pending')
        ->and($t[7]->away)->toBeNull();
});

test('editing a result recomputes the entire tree (ripple)', function () {
    $edited = fullResults();
    $edited[0] = new TieResult(1, 1, 3);

    $result = BracketResolver::resolve(knockoutTies(), $edited, knockoutSeeds());
    $t = tiesById($result);

    expect($t[1]->winner->name)->toBe('França')
        ->and($t[5]->home->name)->toBe('França')
        ->and($result['champion']->name)->toBe('França');
});

test('a missing seeded slot leaves the tie pending', function () {
    $seeds = knockoutSeeds();
    unset($seeds['B2']);

    $t = tiesById(BracketResolver::resolve(knockoutTies(), [], $seeds));

    expect($t[1]->status)->toBe('pending')->and($t[1]->away)->toBeNull();
});

test('a cyclic bracket throws an exception instead of looping', function () {
    $cyclic = [
        new Tie(1, 1, SlotSource::winnerOf(2), SlotSource::seed('A1')),
        new Tie(2, 1, SlotSource::winnerOf(1), SlotSource::seed('A2')),
    ];

    BracketResolver::resolve($cyclic, [], knockoutSeeds());
})->throws(RuntimeException::class);
