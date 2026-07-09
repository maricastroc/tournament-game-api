<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Standings;

/**
 * A cadeia ordenada de critérios de desempate.
 * Construtores nomeados por regulamento; ::of() para cenários de teste.
 */
final class TiebreakRules
{
    /** @param Criterion[] $criteria */
    private function __construct(public readonly array $criteria) {}

    /**
     * Ordem estilo Copa do Mundo: pontos, saldo geral, gols pró geral,
     * depois confronto direto entre os empatados e, por fim, número de vitórias.
     * (Fair play e sorteio ficam fora da engine pura — um é dado externo, o outro é aleatório.)
     */
    public static function fifa(): self
    {
        return new self([
            Criterion::Points,
            Criterion::GoalDifference,
            Criterion::GoalsFor,
            Criterion::HeadToHead,
            Criterion::Wins,
        ]);
    }

    public static function of(Criterion ...$criteria): self
    {
        return new self($criteria);
    }
}
