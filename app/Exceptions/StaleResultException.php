<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Lançada quando a versão esperada do resultado não bate com a do banco —
 * ou seja, outra pessoa gravou/editou o mesmo jogo nesse meio-tempo.
 * A API traduz isso para HTTP 409 Conflict; o cliente recarrega e tenta de novo.
 */
final class StaleResultException extends RuntimeException
{
    public function __construct(
        public readonly int|string $fixtureId,
        public readonly int $expectedVersion,
    ) {
        parent::__construct(
            "O resultado do jogo {$fixtureId} foi alterado por outra pessoa "
            ."(versão {$expectedVersion} está desatualizada). Recarregue e tente novamente."
        );
    }
}
