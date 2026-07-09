<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Input;

/**
 * Resultado de uma partida encerrada — DTO puro de entrada da engine.
 * A camada de aplicação (Action) mapeia Eloquent -> este objeto.
 * Só partidas com placar definido chegam aqui.
 */
final class MatchResult
{
    public function __construct(
        public readonly int $homeTeamId,
        public readonly int $awayTeamId,
        public readonly int $homeScore,
        public readonly int $awayScore,
    ) {
    }
}
