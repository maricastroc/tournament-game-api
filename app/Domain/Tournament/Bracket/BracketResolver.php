<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Bracket;

use App\Domain\Tournament\Input\TeamRef;
use RuntimeException;

final class BracketResolver
{
    /**
     * @param  Tie[]
     * @param  TieResult[]  $results
     * @param  array<string,TeamRef>
     * @return array{ties: ResolvedTie[], champion: ?TeamRef}
     */
    public static function resolve(array $ties, array $results, array $seeds): array
    {
        $tieById = [];
        foreach ($ties as $tie) {
            $tieById[$tie->id] = $tie;
        }

        $resultByTie = [];
        foreach ($results as $result) {
            $resultByTie[$result->tieId] = $result;
        }

        /** @var array<int, ?TeamRef> $winnerCache */
        $winnerCache = [];
        /** @var array<int, true> $resolving */
        $resolving = [];

        $sideTeam = null;

        $winnerOf = function (int $tieId) use (&$winnerOf, &$sideTeam, $tieById, $resultByTie, &$winnerCache, &$resolving): ?TeamRef {
            if (array_key_exists($tieId, $winnerCache)) {
                return $winnerCache[$tieId];
            }
            if (isset($resolving[$tieId])) {
                throw new RuntimeException("Cyclic bracket structure detected in tie {$tieId}.");
            }
            if (! isset($tieById[$tieId])) {
                throw new RuntimeException("Tie {$tieId} is referenced but does not exist.");
            }

            $resolving[$tieId] = true;
            $tie = $tieById[$tieId];
            $home = $sideTeam($tie->home);
            $away = $sideTeam($tie->away);
            $result = $resultByTie[$tieId] ?? null;

            $winner = null;
            if ($home !== null && $away !== null && $result !== null) {
                $winnerId = MatchOutcome::decide($result, $home->id, $away->id);
                $winner = match ($winnerId) {
                    $home->id => $home,
                    $away->id => $away,
                    default => null,
                };
            }

            unset($resolving[$tieId]);

            return $winnerCache[$tieId] = $winner;
        };

        $sideTeam = function (SlotSource $source) use (&$winnerOf, $seeds): ?TeamRef {
            return $source->isSeed()
                ? ($seeds[$source->seed] ?? null)
                : $winnerOf($source->tieId);
        };

        $resolved = [];
        foreach ($ties as $tie) {
            $home = $sideTeam($tie->home);
            $away = $sideTeam($tie->away);
            $winner = $winnerOf($tie->id);
            $result = $resultByTie[$tie->id] ?? null;

            $status = ($home === null || $away === null)
                ? 'pending'
                : ($winner !== null ? 'decided' : 'ready');

            $resolved[] = new ResolvedTie(
                $tie->id,
                $tie->round,
                $home,
                $away,
                $winner,
                $status,
                $result !== null && $result->wentToPenalties(),
            );
        }

        usort($resolved, static fn (ResolvedTie $a, ResolvedTie $b) => [$a->round, $a->id] <=> [$b->round, $b->id]);

        $consumed = [];
        foreach ($ties as $tie) {
            foreach ([$tie->home, $tie->away] as $source) {
                if (! $source->isSeed()) {
                    $consumed[$source->tieId] = true;
                }
            }
        }

        $final = null;
        foreach ($ties as $tie) {
            if (! isset($consumed[$tie->id]) && ($final === null || $tie->round > $final->round)) {
                $final = $tie;
            }
        }

        return [
            'ties' => $resolved,
            'champion' => $final !== null ? $winnerOf($final->id) : null,
        ];
    }
}
