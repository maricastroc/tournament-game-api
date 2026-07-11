<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

final class ScenarioResult
{
    public function __construct(
        public readonly int $homeScore,
        public readonly int $awayScore,
        public readonly ?int $homePenalties = null,
        public readonly ?int $awayPenalties = null,
    ) {}
}
