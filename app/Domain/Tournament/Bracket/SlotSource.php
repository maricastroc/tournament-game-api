<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Bracket;

final class SlotSource
{
    private function __construct(
        public readonly string $kind,
        public readonly ?string $seed,
        public readonly ?int $tieId,
    ) {}

    public static function seed(string $key): self
    {
        return new self('seed', $key, null);
    }

    public static function winnerOf(int $tieId): self
    {
        return new self('winner', null, $tieId);
    }

    public function isSeed(): bool
    {
        return $this->kind === 'seed';
    }
}
