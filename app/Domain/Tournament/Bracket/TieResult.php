<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Bracket;

/**
 * Resultado de um confronto do mata-mata. Placar do tempo normal e, se empatado,
 * a disputa de pênaltis. DTO puro de entrada.
 */
final class TieResult
{
    public function __construct(
        public readonly int $tieId,
        public readonly int $homeScore,
        public readonly int $awayScore,
        public readonly ?int $homePenalties = null,
        public readonly ?int $awayPenalties = null,
    ) {}

    public function isDraw(): bool
    {
        return $this->homeScore === $this->awayScore;
    }

    public function wentToPenalties(): bool
    {
        return $this->isDraw() && $this->homePenalties !== null && $this->awayPenalties !== null;
    }
}
