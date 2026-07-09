<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Bracket;

/**
 * De onde vem um lado de um confronto: ou uma vaga semeada da fase de grupos
 * (ex.: "A1" = 1º do grupo A), ou o vencedor de um confronto anterior.
 *
 * Isso torna o bracket uma TOPOLOGIA: "quem está na vaga X" é derivável andando
 * pelos sources, não um campo que se reescreve.
 */
final class SlotSource
{
    private function __construct(
        public readonly string $kind,   // 'seed' | 'winner'
        public readonly ?string $seed,  // ex.: 'A1' quando kind = seed
        public readonly ?int $tieId,    // id do confronto de origem quando kind = winner
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
