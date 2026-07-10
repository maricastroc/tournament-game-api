<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Fixture;

/**
 * Generates the single round-robin of a group — pure engine, no framework.
 *
 * Circle method (round-robin): each pair of teams faces each other once.
 * With an odd number of teams, a "bye" is added to sit out the round. Home/away
 * alternates per round just to balance it out — within a group it is neutral.
 */
final class RoundRobinScheduler
{
    private const BYE = -1;

    /**
     * @param  int[]  $teamIds  ids of the group's teams, in entry order
     * @return list<array{home: int, away: int}>  the ties, each pair once
     */
    public static function schedule(array $teamIds): array
    {
        $teams = array_values($teamIds);
        if (count($teams) < 2) {
            return [];
        }

        if (count($teams) % 2 === 1) {
            $teams[] = self::BYE;
        }

        $count = count($teams);
        $rounds = $count - 1;
        $half = intdiv($count, 2);
        $fixtures = [];

        for ($round = 0; $round < $rounds; $round++) {
            for ($i = 0; $i < $half; $i++) {
                $a = $teams[$i];
                $b = $teams[$count - 1 - $i];

                if ($a === self::BYE || $b === self::BYE) {
                    continue;
                }

                [$home, $away] = $round % 2 === 0 ? [$a, $b] : [$b, $a];
                $fixtures[] = ['home' => $home, 'away' => $away];
            }

            $last = array_pop($teams);
            array_splice($teams, 1, 0, [$last]);
        }

        return $fixtures;
    }
}
