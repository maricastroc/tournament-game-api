<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Tournament\Bracket\ResolvedTie;
use App\Domain\Tournament\Input\TeamRef;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formata o chaveamento resolvido (saída do BracketResolver) para JSON.
 *
 * @property-read array{ties: ResolvedTie[], champion: ?TeamRef} $resource
 */
final class BracketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'champion' => self::team($this->resource['champion']),
            'ties' => array_map(fn (ResolvedTie $tie) => [
                'id' => $tie->id,
                'round' => $tie->round,
                'status' => $tie->status,
                'home' => self::team($tie->home),
                'away' => self::team($tie->away),
                'winner' => self::team($tie->winner),
                'decided_by_penalties' => $tie->decidedByPenalties,
            ], $this->resource['ties']),
        ];
    }

    /** @return array{id: int, name: string}|null */
    private static function team(?TeamRef $team): ?array
    {
        return $team === null ? null : ['id' => $team->id, 'name' => $team->name];
    }
}
