<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

use App\Domain\Tournament\Bracket\ResolvedTie;
use App\Domain\Tournament\Input\TeamRef;
use App\Exceptions\StaleResultException;
use App\Models\Fixture;
use Illuminate\Support\Facades\DB;

/**
 * Escrita de resultado do mata-mata: grava placar + pênaltis sob lock otimista e
 * devolve o chaveamento re-resolvido. O avanço propaga sozinho (é projeção), então
 * a transação só protege a gravação do resultado da própria partida.
 */
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
        return DB::transaction(function () use ($fixture, $homeScore, $awayScore, $expectedVersion, $homePenalties, $awayPenalties) {
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

            return $this->bracket->for($fixture->stage()->firstOrFail());
        });
    }
}
