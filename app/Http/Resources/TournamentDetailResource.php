<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Fixture;
use App\Models\Group;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tie;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The full view of a tournament — the read model the UI consumes to know the
 * structure (stages, groups, ids) and list matches with their version (for the console).
 *
 * Expects the relations already loaded: stages.groups.teams, stages.fixtures.homeTeam,
 * stages.fixtures.awayTeam, stages.ties.
 *
 * @property-read Tournament $resource
 */
final class TournamentDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user('sanctum');

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'status' => $this->resource->status,
            'can_manage' => $user !== null && $user->id === $this->resource->user_id,
            'teams' => $this->resource->teams->map(fn (Team $team) => self::team($team))->all(),
            'stages' => $this->resource->stages
                ->sortBy('position')
                ->values()
                ->map(fn (Stage $stage) => self::stage($stage))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function stage(Stage $stage): array
    {
        $base = [
            'id' => $stage->id,
            'type' => $stage->type,
            'name' => $stage->name,
            'position' => $stage->position,
        ];

        if ($stage->type === 'group') {
            return $base + [
                'groups' => $stage->groups->map(
                    fn (Group $group) => self::group($group, $stage->fixtures)
                )->all(),
            ];
        }

        return $base + [
            'ties' => $stage->ties
                ->sortBy(['round', 'slot'])
                ->values()
                ->map(fn (Tie $tie) => [
                    'id' => $tie->id,
                    'round' => $tie->round,
                    'slot' => $tie->slot,
                    'home_source' => $tie->home_source,
                    'away_source' => $tie->away_source,
                ])->all(),
            'fixtures' => $stage->fixtures->map(fn (Fixture $fixture) => self::fixture($fixture))->all(),
        ];
    }

    /**
     * @param  Collection<int, Fixture>  $stageFixtures
     * @return array<string, mixed>
     */
    private static function group(Group $group, $stageFixtures): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'qualify_count' => $group->qualify_count,
            'teams' => $group->teams->map(fn (Team $team) => self::team($team))->all(),
            'fixtures' => $stageFixtures
                ->where('group_id', $group->id)
                ->values()
                ->map(fn (Fixture $fixture) => self::fixture($fixture))
                ->all(),
        ];
    }

    /** @return array{id: int, name: string, code: ?string, flag: ?string} */
    private static function team(Team $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'code' => $team->code,
            'flag' => $team->flag,
        ];
    }

    /** @return array<string, mixed> */
    private static function fixture(Fixture $fixture): array
    {
        return [
            'id' => $fixture->id,
            'tie_id' => $fixture->tie_id,
            'home' => self::teamRef($fixture->homeTeam),
            'away' => self::teamRef($fixture->awayTeam),
            'home_score' => $fixture->home_score,
            'away_score' => $fixture->away_score,
            'home_penalties' => $fixture->home_penalties,
            'away_penalties' => $fixture->away_penalties,
            'status' => $fixture->status,
            'version' => $fixture->version,
        ];
    }

    /** @return array{id: int, name: string, flag: ?string}|null */
    private static function teamRef(?Team $team): ?array
    {
        return $team === null ? null : ['id' => $team->id, 'name' => $team->name, 'flag' => $team->flag];
    }
}
