<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

use App\Domain\Tournament\Input\MatchResult;
use App\Domain\Tournament\Input\TeamRef;
use App\Domain\Tournament\Standings\GroupTable;
use App\Domain\Tournament\Standings\Standing;
use App\Domain\Tournament\Standings\TiebreakRules;
use App\Exceptions\StaleResultException;
use App\Models\Fixture;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Grava (ou edita) o resultado de um jogo de grupo e devolve a classificação recalculada.
 *
 * É a fronteira entre Laravel e o Domain puro:
 *   1. grava o placar sob lock OTIMISTA (só se a versão bater) — protege edição concorrente;
 *   2. traduz Eloquent -> DTOs;
 *   3. delega o cálculo à engine pura GroupTable;
 * tudo numa única transação, para a classificação nunca refletir um estado parcial.
 *
 * A classificação em si não é gravada: é PROJEÇÃO das partidas. Editar um resultado é
 * só recomputar — não sincronizar estado.
 */
final class ConfirmMatchResult
{
    /**
     * @return Standing[] a classificação recalculada do grupo do jogo
     *
     * @throws StaleResultException se outra pessoa alterou o jogo nesse meio-tempo
     */
    public function handle(Fixture $fixture, int $homeScore, int $awayScore, int $expectedVersion): array
    {
        return DB::transaction(function () use ($fixture, $homeScore, $awayScore, $expectedVersion) {
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

            $group = $fixture->group()->with('teams')->firstOrFail();

            // borda: Eloquent -> DTO puro
            $teams = $group->teams
                ->map(fn (Team $team) => new TeamRef($team->id, $team->name))
                ->all();

            $results = Fixture::where('group_id', $group->id)
                ->finished()
                ->get()
                ->map(fn (Fixture $f) => new MatchResult(
                    $f->home_team_id,
                    $f->away_team_id,
                    $f->home_score,
                    $f->away_score,
                ))
                ->all();

            // núcleo puro, determinístico
            return GroupTable::compute($teams, $results, TiebreakRules::fifa(), $group->qualify_count);
        });
    }
}
