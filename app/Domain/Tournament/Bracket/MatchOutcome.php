<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Bracket;

/**
 * Decide o vencedor de um confronto do mata-mata: tempo normal e, no empate,
 * pênaltis. Função pura — devolve o id do time vencedor, ou null se indefinido
 * (empate sem pênaltis: não deveria acontecer no mata-mata, então fica "não decidido").
 */
final class MatchOutcome
{
    public static function decide(TieResult $result, int $homeTeamId, int $awayTeamId): ?int
    {
        if ($result->homeScore > $result->awayScore) {
            return $homeTeamId;
        }

        if ($result->awayScore > $result->homeScore) {
            return $awayTeamId;
        }

        // empate no tempo normal -> pênaltis
        if ($result->homePenalties !== null && $result->awayPenalties !== null) {
            if ($result->homePenalties > $result->awayPenalties) {
                return $homeTeamId;
            }
            if ($result->awayPenalties > $result->homePenalties) {
                return $awayTeamId;
            }
        }

        return null;
    }
}
