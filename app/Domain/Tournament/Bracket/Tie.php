<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Bracket;

/**
 * Um confronto do mata-mata — só a topologia (id, rodada e de onde vêm os dois lados).
 * O resultado vem à parte, em TieResult, porque o confronto existe antes de ser jogado.
 */
final class Tie
{
    public function __construct(
        public readonly int $id,
        public readonly int $round,       // 1 = primeira rodada; maior = mais tarde (final é a maior)
        public readonly SlotSource $home,
        public readonly SlotSource $away,
    ) {}
}
