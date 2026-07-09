<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Standings;

/**
 * Critérios de desempate. A ordem em que são aplicados vive em TiebreakRules,
 * então trocar de regulamento é trocar a lista — não mexer na engine.
 *
 * Os escalares (Points/GoalDifference/GoalsFor/Wins) são comparados globalmente.
 * HeadToHead é especial: reconstrói uma mini-tabela só entre os times empatados.
 */
enum Criterion
{
    case Points;
    case GoalDifference;
    case GoalsFor;
    case HeadToHead;
    case Wins;
}
