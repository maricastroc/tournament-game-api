<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

use App\Domain\Tournament\Bracket\KnockoutSeeder;
use App\Exceptions\InvalidTournamentStructure;
use App\Models\Fixture;
use App\Models\Stage;
use App\Models\Tie;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BuildKnockout
{
    public function handle(Tournament $tournament): Stage
    {
        $groupStage = $tournament->stages()->where('type', 'group')->first();

        if ($groupStage === null) {
            throw new InvalidTournamentStructure('Create the group stage before generating the knockout bracket.');
        }

        if ($tournament->stages()->where('type', 'knockout')->exists()) {
            throw new InvalidTournamentStructure('The knockout bracket for this tournament has already been generated.');
        }

        $groups = $groupStage->groups()->orderBy('name')->get();
        $groupNames = $groups->pluck('name')->all();
        $qualifyCount = (int) ($groups->first()?->qualify_count ?? 2);

        try {
            $topology = KnockoutSeeder::seed($groupNames, $qualifyCount);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidTournamentStructure($exception->getMessage());
        }

        usort($topology, fn (array $a, array $b) => [$a['round'], $a['slot']] <=> [$b['round'], $b['slot']]);

        return DB::transaction(function () use ($tournament, $topology) {
            $stage = Stage::create([
                'tournament_id' => $tournament->id,
                'type' => 'knockout',
                'name' => 'Knockout',
                'position' => 2,
            ]);

            /** @var array<string, int> $idByRef "r{R}s{S}" => already inserted tie id */
            $idByRef = [];

            foreach ($topology as $entry) {
                $tie = Tie::create([
                    'stage_id' => $stage->id,
                    'round' => $entry['round'],
                    'slot' => $entry['slot'],
                    'home_source' => self::resolve($entry['home_source'], $idByRef),
                    'away_source' => self::resolve($entry['away_source'], $idByRef),
                ]);

                $idByRef["r{$entry['round']}s{$entry['slot']}"] = $tie->id;

                Fixture::create([
                    'tournament_id' => $tournament->id,
                    'stage_id' => $stage->id,
                    'tie_id' => $tie->id,
                    'status' => 'scheduled',
                ]);
            }

            return $stage;
        });
    }

    /** @param  array<string, int>  $idByRef */
    private static function resolve(string $source, array $idByRef): string
    {
        if (! str_starts_with($source, 'winner:r')) {
            return $source;
        }

        $ref = substr($source, strlen('winner:'));
        $id = $idByRef[$ref] ?? null;

        if ($id === null) {
            throw new InvalidTournamentStructure("Unresolved winner reference: {$source}.");
        }

        return "winner:{$id}";
    }
}
