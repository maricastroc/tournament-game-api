<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Bracket;

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
