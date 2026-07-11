<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Tournament\Standings\Standing;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{
 *     groups: array<int, array{group: Group, standings: Standing[]}>,
 *     bracket: array{ties: mixed, champion: mixed}|null
 * } $resource
 */
final class ScenarioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'groups' => array_map(fn (array $group) => [
                'id' => $group['group']->id,
                'name' => $group['group']->name,
                'qualify_count' => $group['group']->qualify_count,
                'standings' => array_map(
                    fn (Standing $standing) => (new StandingResource($standing))->toArray($request),
                    $group['standings'],
                ),
            ], $this->resource['groups']),
            'bracket' => $this->resource['bracket'] === null
                ? null
                : (new BracketResource($this->resource['bracket']))->toArray($request),
        ];
    }
}
