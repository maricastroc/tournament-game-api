<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

use App\Domain\Tournament\Input\MatchResult;
use App\Domain\Tournament\Input\TeamRef;
use App\Domain\Tournament\Standings\GroupTable;
use App\Domain\Tournament\Standings\Standing;
use App\Domain\Tournament\Standings\TiebreakRules;
use App\Models\Fixture;
use App\Models\Group;
use App\Models\Team;

/**
 * The READ boundary: maps a group (Eloquent) to the Domain DTOs and delegates
 * the computation to the pure engine. Reused by the write path (ConfirmMatchResult),
 * the standings read endpoint, and the scenario projection (via an optional overlay).
 */
final class ComputeGroupStandings
{
    /** @return Standing[] */
    public function for(Group $group, ?ScenarioOverlay $overlay = null): array
    {
        $group->loadMissing('teams');
        $overlay ??= ScenarioOverlay::none();

        $teams = $group->teams
            ->map(fn (Team $team) => new TeamRef($team->id, $team->name))
            ->all();

        $query = Fixture::where('group_id', $group->id);
        $fixtures = $overlay->isEmpty() ? $query->finished()->get() : $query->get();

        $results = [];
        foreach ($fixtures as $fixture) {
            $hypothetical = $overlay->for($fixture->id);

            if ($hypothetical !== null) {
                $results[] = new MatchResult(
                    $fixture->home_team_id,
                    $fixture->away_team_id,
                    $hypothetical->homeScore,
                    $hypothetical->awayScore,
                );
            } elseif ($fixture->status === 'finished') {
                $results[] = new MatchResult(
                    $fixture->home_team_id,
                    $fixture->away_team_id,
                    $fixture->home_score,
                    $fixture->away_score,
                );
            }
        }

        return GroupTable::compute($teams, $results, TiebreakRules::fifa(), $group->qualify_count);
    }
}
