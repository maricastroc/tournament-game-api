<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Bracket;

use InvalidArgumentException;

final class KnockoutSeeder
{
    /**
     * @param  string[]
     * @param  int
     * @return list<array{round: int, slot: int, home_source: string, away_source: string}>
     */
    public static function seed(array $groupNames, int $qualifyCount): array
    {
        $names = array_values($groupNames);
        sort($names);
        $groups = count($names);
        $qualified = $groups * $qualifyCount;

        self::guard($names, $qualifyCount, $qualified);

        $ties = self::firstRound($names, $qualifyCount);

        $round = 2;
        $previousCount = count($ties);
        while ($previousCount > 1) {
            $currentCount = intdiv($previousCount, 2);
            for ($slot = 1; $slot <= $currentCount; $slot++) {
                $ties[] = [
                    'round' => $round,
                    'slot' => $slot,
                    'home_source' => 'winner:r'.($round - 1).'s'.(2 * $slot - 1),
                    'away_source' => 'winner:r'.($round - 1).'s'.(2 * $slot),
                ];
            }
            $previousCount = $currentCount;
            $round++;
        }

        return $ties;
    }

    /**
     * @param  string[]  $names
     * @return list<array{round: int, slot: int, home_source: string, away_source: string}>
     */
    private static function firstRound(array $names, int $qualifyCount): array
    {
        $ties = [];
        $slot = 1;

        if ($qualifyCount === 1) {
            foreach (array_chunk($names, 2) as [$x, $y]) {
                $ties[] = self::tie(1, $slot++, "seed:{$x}1", "seed:{$y}1");
            }

            return $ties;
        }

        $pairs = array_chunk($names, 2);
        foreach ($pairs as [$x, $y]) {
            $ties[] = self::tie(1, $slot++, "seed:{$x}1", "seed:{$y}2");
        }
        foreach ($pairs as [$x, $y]) {
            $ties[] = self::tie(1, $slot++, "seed:{$y}1", "seed:{$x}2");
        }

        return $ties;
    }

    /** @return array{round: int, slot: int, home_source: string, away_source: string} */
    private static function tie(int $round, int $slot, string $home, string $away): array
    {
        return ['round' => $round, 'slot' => $slot, 'home_source' => $home, 'away_source' => $away];
    }

    /** @param  string[]  $names */
    private static function guard(array $names, int $qualifyCount, int $qualified): void
    {
        if (! in_array($qualifyCount, [1, 2], true)) {
            throw new InvalidArgumentException('It is only possible to generate a bracket with 1 or 2 qualified teams per group.');
        }

        if (! in_array($qualified, [4, 8, 16], true)) {
            throw new InvalidArgumentException(
                "The total number of qualified teams must be 4, 8, or 16 (received {$qualified})."
            );
        }

        if ($qualifyCount === 2 && count($names) % 2 !== 0) {
            throw new InvalidArgumentException('With 2 qualified teams per group, the number of groups must be even.');
        }
    }
}
