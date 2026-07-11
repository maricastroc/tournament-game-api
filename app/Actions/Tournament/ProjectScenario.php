<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

use App\Domain\Tournament\Bracket\ResolvedTie;
use App\Domain\Tournament\Input\TeamRef;
use App\Domain\Tournament\Standings\Standing;
use App\Models\Group;
use App\Models\Stage;
use App\Models\Tournament;

final class ProjectScenario
{
    public function __construct(
        private readonly ComputeGroupStandings $standings = new ComputeGroupStandings,
        private readonly ResolveBracket $bracket = new ResolveBracket,
    ) {}

    /**
     * @return array{
     *     groups: array<int, array{group: Group, standings: Standing[]}>,
     *     bracket: array{ties: ResolvedTie[], champion: ?TeamRef}|null
     * }
     */
    public function for(Tournament $tournament, ScenarioOverlay $overlay): array
    {
        $tournament->loadMissing(['stages.groups.teams']);

        $groups = [];
        $groupStage = $tournament->stages->firstWhere('type', 'group');
        if ($groupStage instanceof Stage) {
            foreach ($groupStage->groups as $group) {
                $groups[] = [
                    'group' => $group,
                    'standings' => $this->standings->for($group, $overlay),
                ];
            }
        }

        $knockout = $tournament->stages->firstWhere('type', 'knockout');
        $bracket = $knockout instanceof Stage
            ? $this->bracket->for($knockout, $overlay)
            : null;

        return ['groups' => $groups, 'bracket' => $bracket];
    }
}
