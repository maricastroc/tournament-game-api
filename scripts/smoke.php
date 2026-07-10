<?php

declare(strict_types=1);

use App\Domain\Tournament\Input\MatchResult;
use App\Domain\Tournament\Input\TeamRef;
use App\Domain\Tournament\Standings\GroupTable;
use App\Domain\Tournament\Standings\TiebreakRules;

$base = dirname(__DIR__) . '/app/Domain/Tournament';
require $base . '/Input/MatchResult.php';
require $base . '/Input/TeamRef.php';
require $base . '/Standings/Criterion.php';
require $base . '/Standings/TiebreakRules.php';
require $base . '/Standings/Standing.php';
require $base . '/Standings/GroupTable.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  \033[32m✓\033[0m " : "  \033[31m✗ FALHOU\033[0m ") . $label . PHP_EOL;
}

/** @param array $table @return array<int,int> id do time => posição */
function positions(array $table): array
{
    $out = [];
    foreach ($table as $s) {
        $out[$s->team->id] = $s->position;
    }
    return $out;
}

$fifa = TiebreakRules::fifa();

echo "\nCENÁRIO 1 — Grupo A do mock (conservação + ordenação básica)\n";
$teams = [new TeamRef(1, 'Brasil'), new TeamRef(2, 'Croácia'), new TeamRef(3, 'Marrocos'), new TeamRef(4, 'Japão')];
$matches = [
    new MatchResult(1, 3, 2, 0),
    new MatchResult(2, 4, 1, 1),
    new MatchResult(1, 2, 1, 1),
    new MatchResult(4, 3, 2, 1),
];
$table = GroupTable::compute($teams, $matches, $fifa);
$pos = positions($table);

check('Brasil em 1º (4 pts, saldo +2)', $pos[1] === 1);
check('Japão em 2º (4 pts, saldo +1)', $pos[4] === 2);
check('Croácia em 3º (2 pts)', $pos[2] === 3);
check('Marrocos em 4º (0 pts)', $pos[3] === 4);
check('Top 2 classificados', $table[0]->qualified && $table[1]->qualified && !$table[2]->qualified && !$table[3]->qualified);

$sumPts = array_sum(array_map(fn ($s) => $s->points, $table));
$expectedPts = array_sum(array_map(fn ($m) => $m->homeScore === $m->awayScore ? 2 : 3, $matches));
check("Conservação de pontos (soma = {$expectedPts})", $sumPts === $expectedPts);

$sumPlayed = array_sum(array_map(fn ($s) => $s->played, $table));
check('Conservação de jogos (soma J = 2 × nº de partidas)', $sumPlayed === 2 * count($matches));

$sumGf = array_sum(array_map(fn ($s) => $s->goalsFor, $table));
$sumGa = array_sum(array_map(fn ($s) => $s->goalsAgainst, $table));
check('Conservação de gols (Σ GP = Σ GC)', $sumGf === $sumGa);

echo "\nCENÁRIO 2 — Empate em pontos resolvido pelo SALDO\n";
$teams = [new TeamRef(1, 'A'), new TeamRef(2, 'B'), new TeamRef(3, 'C')];
$matches = [
    new MatchResult(1, 3, 3, 0),
    new MatchResult(2, 3, 1, 0),
    new MatchResult(1, 2, 0, 0),
];
$pos = positions(GroupTable::compute($teams, $matches, $fifa));
check('A à frente de B por saldo de gols', $pos[1] === 1 && $pos[2] === 2);

echo "\nCENÁRIO 3 — Empate em pontos e saldo resolvido por GOLS PRÓ\n";
$teams = [new TeamRef(1, 'A'), new TeamRef(2, 'B'), new TeamRef(3, 'C')];
$matches = [
    new MatchResult(1, 3, 2, 1),
    new MatchResult(2, 3, 1, 0),
    new MatchResult(1, 2, 1, 1),
];
$pos = positions(GroupTable::compute($teams, $matches, $fifa));
check('A à frente de B por gols pró', $pos[1] === 1 && $pos[2] === 2);

echo "\nCENÁRIO 4 — Empate total resolvido pelo CONFRONTO DIRETO\n";
$teams = [new TeamRef(2, 'B'), new TeamRef(1, 'A'), new TeamRef(3, 'C')];
$matches = [
    new MatchResult(1, 2, 2, 1),
    new MatchResult(1, 3, 0, 1),
    new MatchResult(2, 3, 1, 0),
];
$pos = positions(GroupTable::compute($teams, $matches, $fifa));
check('A subiu sobre B pelo confronto direto (contra a ordem de entrada)', $pos[1] === 1 && $pos[2] === 2);
check('C em 3º (menos gols pró)', $pos[3] === 3);

echo "\nCENÁRIO 5 — Ciclo A>B>C>A: empate irredutível => ordem determinística de entrada\n";
$matches = [
    new MatchResult(1, 2, 1, 0),
    new MatchResult(2, 3, 1, 0),
    new MatchResult(3, 1, 1, 0),
];
$in1 = [new TeamRef(1, 'A'), new TeamRef(2, 'B'), new TeamRef(3, 'C')];
$in2 = [new TeamRef(3, 'C'), new TeamRef(2, 'B'), new TeamRef(1, 'A')];
$order1 = array_map(fn ($s) => $s->team->id, GroupTable::compute($in1, $matches, $fifa));
$order2 = array_map(fn ($s) => $s->team->id, GroupTable::compute($in2, $matches, $fifa));
check('Ordem [A,B,C] preservada quando entra [A,B,C]', $order1 === [1, 2, 3]);
check('Ordem [C,B,A] preservada quando entra [C,B,A]', $order2 === [3, 2, 1]);
check('Determinismo: recomputar dá o mesmo resultado', $order1 === array_map(fn ($s) => $s->team->id, GroupTable::compute($in1, $matches, $fifa)));

echo "\nCENÁRIO 6 — Monotonicidade: melhorar um resultado nunca rebaixa o time\n";
$teams = [new TeamRef(1, 'A'), new TeamRef(2, 'B')];
$before = GroupTable::compute($teams, [new MatchResult(1, 2, 0, 0)], $fifa);
$after = GroupTable::compute($teams, [new MatchResult(1, 2, 1, 0)], $fifa);
$aBeforePos = positions($before)[1];
$aAfterPos = positions($after)[1];
$aBeforePts = array_values(array_filter($before, fn ($s) => $s->team->id === 1))[0]->points;
$aAfterPts = array_values(array_filter($after, fn ($s) => $s->team->id === 1))[0]->points;
check('Pontos de A subiram (1 -> 3)', $aBeforePts === 1 && $aAfterPts === 3);
check('Posição de A não piorou', $aAfterPos <= $aBeforePos);

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "\033[32mTODOS OS {$pass} CHECKS PASSARAM\033[0m\n"
    : "\033[31m{$fail} FALHA(S)\033[0m de " . ($pass + $fail) . " checks\n";
exit($fail === 0 ? 0 : 1);
