<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Input;

/**
 * Referência mínima de um time — DTO puro de entrada da engine.
 * Não é o model Eloquent: só o que a engine precisa para ranquear.
 */
final class TeamRef
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }
}
