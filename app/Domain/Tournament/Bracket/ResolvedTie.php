<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Bracket;

use App\Domain\Tournament\Input\TeamRef;

final class ResolvedTie
{
    public function __construct(
        public readonly int $id,
        public readonly int $round,
        public readonly ?TeamRef $home,
        public readonly ?TeamRef $away,
        public readonly ?TeamRef $winner,
        public readonly string $status,
        public readonly bool $decidedByPenalties,
    ) {}
}
