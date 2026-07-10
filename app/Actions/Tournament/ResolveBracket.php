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

/**
 * A borda de LEITURA do mata-mata: monta a topologia e os resultados (Eloquent) e
 * as sementes (a partir da classificação dos grupos) e delega ao BracketResolver puro.
 *
 * O chaveamento é derivado: nada de "quem avançou" fica gravado — é recomputado da
 * topologia + resultados. As sementes 'A1', 'B2'... vêm da projeção dos grupos.
 */
final class ResolveBracket
{
    public function __construct(private readonly ComputeGroupStandings $standings = new ComputeGroupStandings) {}

    /** @return array{ties: ResolvedTie[], champion: ?TeamRef} */
    public function for(Stage $stage): array
    {
        $ties = $stage->ties()->orderBy('round')->orderBy('slot')->get();

        $topology = $ties->map(fn (Tie $tie) => new TieDto(
            $tie->id,
            $tie->round,
            self::parseSource($tie->home_source),
            self::parseSource($tie->away_source),
        ))->all();

        $results = Fixture::whereIn('tie_id', $ties->modelKeys())
            ->finished()
            ->get()
            ->map(fn (Fixture $fixture) => new TieResult(
                $fixture->tie_id,
                $fixture->home_score,
                $fixture->away_score,
                $fixture->home_penalties,
                $fixture->away_penalties,
            ))->all();

        return BracketResolver::resolve($topology, $results, $this->seeds($stage));
    }

    /** @return array<string, TeamRef>  ex.: ['A1' => TeamRef, 'B2' => TeamRef, ...] */
    private function seeds(Stage $knockout): array
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
            foreach ($this->standings->for($group) as $standing) {
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
