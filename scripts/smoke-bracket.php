<?php

declare(strict_types=1);

// Runner de fumaça do mata-mata — PHP puro, sem framework.
// Uso:  php scripts/smoke-bracket.php   (a partir da raiz do projeto)

use App\Domain\Tournament\Input\TeamRef;
use App\Domain\Tournament\Bracket\BracketResolver;
use App\Domain\Tournament\Bracket\SlotSource;
use App\Domain\Tournament\Bracket\Tie;
use App\Domain\Tournament\Bracket\TieResult;

$base = dirname(__DIR__) . '/app/Domain/Tournament';
require $base . '/Input/TeamRef.php';
require $base . '/Bracket/SlotSource.php';
require $base . '/Bracket/Tie.php';
require $base . '/Bracket/TieResult.php';
require $base . '/Bracket/ResolvedTie.php';
require $base . '/Bracket/MatchOutcome.php';
require $base . '/Bracket/BracketResolver.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  \033[32m✓\033[0m " : "  \033[31m✗ FALHOU\033[0m ") . $label . PHP_EOL;
}

/** @return array<int,\App\Domain\Tournament\Bracket\ResolvedTie> id => confronto resolvido */
function byId(array $resolved): array
{
    $out = [];
    foreach ($resolved['ties'] as $t) {
        $out[$t->id] = $t;
    }
    return $out;
}

// Topologia: 4 quartas -> 2 semis -> 1 final
$ties = [
    new Tie(1, 1, SlotSource::seed('A1'), SlotSource::seed('B2')),
    new Tie(2, 1, SlotSource::seed('C1'), SlotSource::seed('D2')),
    new Tie(3, 1, SlotSource::seed('B1'), SlotSource::seed('A2')),
    new Tie(4, 1, SlotSource::seed('D1'), SlotSource::seed('C2')),
    new Tie(5, 2, SlotSource::winnerOf(1), SlotSource::winnerOf(2)),
    new Tie(6, 2, SlotSource::winnerOf(3), SlotSource::winnerOf(4)),
    new Tie(7, 3, SlotSource::winnerOf(5), SlotSource::winnerOf(6)),
];

$seeds = [
    'A1' => new TeamRef(1, 'Brasil'),
    'B2' => new TeamRef(2, 'França'),
    'C1' => new TeamRef(3, 'Espanha'),
    'D2' => new TeamRef(4, 'Portugal'),
    'B1' => new TeamRef(5, 'Argentina'),
    'A2' => new TeamRef(6, 'Japão'),
    'D1' => new TeamRef(7, 'Itália'),
    'C2' => new TeamRef(8, 'Alemanha'),
];

$full = [
    new TieResult(1, 3, 1),            // Brasil 3-1 França
    new TieResult(2, 2, 2, 4, 3),      // Espanha 2-2 Portugal (pên. 4-3)
    new TieResult(3, 1, 0),            // Argentina 1-0 Japão
    new TieResult(4, 0, 2),            // Itália 0-2 Alemanha
    new TieResult(5, 2, 1),            // Brasil 2-1 Espanha
    new TieResult(6, 1, 1, 5, 4),      // Argentina 1-1 Alemanha (pên. 5-4)
    new TieResult(7, 1, 0),            // Brasil 1-0 Argentina
];

// ---------------------------------------------------------------------------
echo "\nCENÁRIO 1 — Torneio completo: derivação e campeão\n";
$r = BracketResolver::resolve($ties, $full, $seeds);
$t = byId($r);

check('Semifinal 1 herdou os vencedores das quartas (Brasil × Espanha)',
    $t[5]->home?->name === 'Brasil' && $t[5]->away?->name === 'Espanha');
check('Quarta 2 decidida nos pênaltis (Espanha)',
    $t[2]->decidedByPenalties && $t[2]->winner?->name === 'Espanha');
check('Semifinal 2 decidida nos pênaltis (Argentina)',
    $t[6]->decidedByPenalties && $t[6]->winner?->name === 'Argentina');
check('Final: Brasil × Argentina', $t[7]->home?->name === 'Brasil' && $t[7]->away?->name === 'Argentina');
check('Campeão = Brasil', $r['champion']?->name === 'Brasil');
check('Todos os 7 confrontos decididos', count(array_filter($r['ties'], fn ($x) => $x->status === 'decided')) === 7);

// ---------------------------------------------------------------------------
echo "\nCENÁRIO 2 — Propagação de 'a definir' (semifinal 2 sem resultado)\n";
$partial = array_values(array_filter($full, fn (TieResult $x) => $x->tieId !== 6 && $x->tieId !== 7));
$r = BracketResolver::resolve($ties, $partial, $seeds);
$t = byId($r);

check('Semifinal 2 pronta mas sem vencedor (Argentina × Alemanha)',
    $t[6]->status === 'ready' && $t[6]->home?->name === 'Argentina' && $t[6]->away?->name === 'Alemanha');
check('Final com um lado a definir => status pending',
    $t[7]->status === 'pending' && $t[7]->home?->name === 'Brasil' && $t[7]->away === null);
check('Sem campeão enquanto a final não é decidida', $r['champion'] === null);

// ---------------------------------------------------------------------------
echo "\nCENÁRIO 3 — Ripple: editar uma quarta muda o semifinalista\n";
// Reverte a quarta 1: França vence no lugar do Brasil. A semi 1 deve trocar de participante.
$edited = $full;
$edited[0] = new TieResult(1, 1, 3); // França 3-1 Brasil
$r = BracketResolver::resolve($ties, $edited, $seeds);
$t = byId($r);

check('Vencedor da quarta 1 agora é França', $t[1]->winner?->name === 'França');
check('Semifinal 1 recebeu França no lugar de Brasil', $t[5]->home?->name === 'França');
check('Resultado 2-1 da semi agora define França como vencedora (mando mantido)',
    $t[5]->winner?->name === 'França');
check('Campeão recomputado a partir da nova árvore', $r['champion']?->name === 'França');

// ---------------------------------------------------------------------------
echo "\nCENÁRIO 4 — Seed ausente => vaga a definir\n";
$missing = $seeds;
unset($missing['B2']); // França não semeada
$r = BracketResolver::resolve($ties, [], $missing);
$t = byId($r);
check('Quarta 1 fica pending com away a definir', $t[1]->status === 'pending' && $t[1]->away === null);

// ---------------------------------------------------------------------------
echo "\nCENÁRIO 5 — Guarda contra chaveamento cíclico\n";
$cyclic = [
    new Tie(1, 1, SlotSource::winnerOf(2), SlotSource::seed('A1')),
    new Tie(2, 1, SlotSource::winnerOf(1), SlotSource::seed('A2')),
];
$threw = false;
try {
    BracketResolver::resolve($cyclic, [], $seeds);
} catch (\RuntimeException $e) {
    $threw = true;
}
check('Ciclo lança RuntimeException em vez de loop infinito', $threw);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "\033[32mTODOS OS {$pass} CHECKS PASSARAM\033[0m\n"
    : "\033[31m{$fail} FALHA(S)\033[0m de " . ($pass + $fail) . " checks\n";
exit($fail === 0 ? 0 : 1);
