<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

use App\Domain\Tournament\Standings\Standing;
use App\Events\TournamentUpdated;
use App\Exceptions\StaleResultException;
use App\Models\Fixture;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;

/**
 * Records (or edits) the result of a group match and returns the recomputed standings.
 *
 * It is the WRITE boundary between Laravel and the pure Domain:
 *   1. records the score under an OPTIMISTIC lock (only if the version matches) — protects against concurrent edits;
 *   2. recomputes the standings by delegating to the pure engine (via ComputeGroupStandings);
 * all in a single transaction, so the standings never reflect a partial state.
 *
 * The standings themselves are not persisted: they are a PROJECTION of the matches. Editing a result is
 * just a recompute — not a state sync.
 */
final class ConfirmMatchResult
{
    public function __construct(private readonly ComputeGroupStandings $standings = new ComputeGroupStandings) {}

    /**
     * @return Standing[] the recomputed standings of the match's group
     *
     * @throws StaleResultException if someone else changed the match in the meantime
     */
    public function handle(Fixture $fixture, int $homeScore, int $awayScore, int $expectedVersion): array
    {
        /** @var array{standings: Standing[], tournament: Tournament} $result */
        $result = DB::transaction(function () use ($fixture, $homeScore, $awayScore, $expectedVersion) {
            $affected = Fixture::whereKey($fixture->getKey())
                ->where('version', $expectedVersion)
                ->update([
                    'home_score' => $homeScore,
                    'away_score' => $awayScore,
                    'status' => 'finished',
                    'version' => $expectedVersion + 1,
                ]);

            if ($affected === 0) {
                throw new StaleResultException($fixture->getKey(), $expectedVersion);
            }

            $group = $fixture->group()->firstOrFail();
            $standings = $this->standings->for($group);

            $tournament = $fixture->tournament()->firstOrFail();
            $tournament->increment('revision');

            return ['standings' => $standings, 'tournament' => $tournament];
        });

        TournamentUpdated::dispatch(
            (int) $result['tournament']->id,
            (int) $result['tournament']->revision,
            'result',
        );

        return $result['standings'];
    }
}
