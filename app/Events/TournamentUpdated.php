<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announced once a result save has COMMITTED — dispatched after the write transaction returns,
 * so it never fires on a 409 (stale version), 403, or 422. Carries only already-public data.
 *
 * Today this is the testable "publish after commit" seam: the shipped SSE transport reads the
 * committed `tournaments.revision` directly, so this event has no listener. It is the plug-in
 * point for a real push broker (Reverb/Redis) — attach a broadcaster here to swap the transport
 * without touching the write path.
 */
final class TournamentUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly int $tournamentId,
        public readonly int $revision,
        public readonly string $type,
    ) {}
}
