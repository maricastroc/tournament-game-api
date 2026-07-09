<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Bracket;

use App\Domain\Tournament\Input\TeamRef;

/**
 * A visão resolvida de um confronto — o que a UI consome. Value object imutável.
 *
 * status:
 *  - 'pending'  algum lado ainda é desconhecido (o confronto de origem não terminou)
 *  - 'ready'    ambos os times definidos, sem resultado ainda
 *  - 'decided'  há vencedor
 */
final class ResolvedTie
{
    public function __construct(
        public readonly int $id,
        public readonly int $round,
        public readonly ?TeamRef $home,      // null = a definir
        public readonly ?TeamRef $away,      // null = a definir
        public readonly ?TeamRef $winner,    // null = ainda não decidido
        public readonly string $status,
        public readonly bool $decidedByPenalties,
    ) {}
}
