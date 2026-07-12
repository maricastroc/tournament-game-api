<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

use App\Domain\Tournament\Bracket\ResolvedTie;
use App\Domain\Tournament\Input\TeamRef;
use App\Events\TournamentUpdated;
use App\Exceptions\StaleResultException;
use App\Models\Fixture;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;

final class ConfirmKnockoutResult
{
    public function __construct(private readonly ResolveBracket $bracket = new ResolveBracket) {}

    /**
     * @return array{ties: ResolvedTie[], champion: ?TeamRef}
     *
     * @throws StaleResultException
     */
    public function handle(
        Fixture $fixture,
        int $homeScore,
        int $awayScore,
        int $expectedVersion,
        ?int $homePenalties = null,
        ?int $awayPenalties = null,
    ): array {
        /** @var array{bracket: array{ties: ResolvedTie[], champion: ?TeamRef}, tournament: Tournament} $result */
        $result = DB::transaction(function () use ($fixture, $homeScore, $awayScore, $expectedVersion, $homePenalties, $awayPenalties) {
            $affected = Fixture::whereKey($fixture->getKey())
                ->where('version', $expectedVersion)
                ->update([
                    'home_score' => $homeScore,
                    'away_score' => $awayScore,
                    'home_penalties' => $homePenalties,
                    'away_penalties' => $awayPenalties,
                    'status' => 'finished',
                    'version' => $expectedVersion + 1,
                ]);

            if ($affected === 0) {
                throw new StaleResultException($fixture->getKey(), $expectedVersion);
            }

            $bracket = $this->bracket->for($fixture->stage()->firstOrFail());

            $tournament = $fixture->tournament()->firstOrFail();
            $tournament->increment('revision');

            return ['bracket' => $bracket, 'tournament' => $tournament];
        });

        TournamentUpdated::dispatch(
            (int) $result['tournament']->id,
            (int) $result['tournament']->revision,
            'result',
        );

        return $result['bracket'];
    }
}
