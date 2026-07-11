<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

use App\Domain\Tournament\Bracket\BracketResolver;
use App\Domain\Tournament\Bracket\ResolvedTie;
use App\Domain\Tournament\Bracket\SlotSource;
use App\Domain\Tournament\Bracket\Tie as TieDto;
use App\Domain\Tournament\Bracket\TieResult;
use App\Domain\Tournament\Input\TeamRef;
use App\Models\Fixture;
use App\Models\Stage;
use App\Models\Tie;

final class ResolveBracket
{
    public function __construct(private readonly ComputeGroupStandings $standings = new ComputeGroupStandings) {}

    /** @return array{ties: ResolvedTie[], champion: ?TeamRef} */
    public function for(Stage $stage, ?ScenarioOverlay $overlay = null): array
    {
        $overlay ??= ScenarioOverlay::none();
        $ties = $stage->ties()->orderBy('round')->orderBy('slot')->get();

        $topology = $ties->map(fn (Tie $tie) => new TieDto(
            $tie->id,
            $tie->round,
            self::parseSource($tie->home_source),
            self::parseSource($tie->away_source),
        ))->all();

        $query = Fixture::whereIn('tie_id', $ties->modelKeys());
        $fixtures = $overlay->isEmpty() ? $query->finished()->get() : $query->get();

        $results = [];
        foreach ($fixtures as $fixture) {
            $hypothetical = $overlay->for($fixture->id);

            if ($hypothetical !== null) {
                $results[] = new TieResult(
                    $fixture->tie_id,
                    $hypothetical->homeScore,
                    $hypothetical->awayScore,
                    $hypothetical->homePenalties,
                    $hypothetical->awayPenalties,
                );
            } elseif ($fixture->status === 'finished') {
                $results[] = new TieResult(
                    $fixture->tie_id,
                    $fixture->home_score,
                    $fixture->away_score,
                    $fixture->home_penalties,
                    $fixture->away_penalties,
                );
            }
        }

        return BracketResolver::resolve($topology, $results, $this->seeds($stage, $overlay));
    }

    /** @return array<string, TeamRef>  ex.: ['A1' => TeamRef, 'B2' => TeamRef, ...] */
    private function seeds(Stage $knockout, ScenarioOverlay $overlay): array
    {
        $groupStage = $knockout->tournament
            ->stages()
            ->where('type', 'group')
            ->with('groups')
            ->first();

        if ($groupStage === null) {
            return [];
        }

        $seeds = [];
        foreach ($groupStage->groups as $group) {
            foreach ($this->standings->for($group, $overlay) as $standing) {
                $seeds[$group->name.$standing->position] = $standing->team;
            }
        }

        return $seeds;
    }

    private static function parseSource(string $raw): SlotSource
    {
        [$kind, $ref] = explode(':', $raw, 2);

        return $kind === 'seed'
            ? SlotSource::seed($ref)
            : SlotSource::winnerOf((int) $ref);
    }
}
