<?php

declare(strict_types=1);

namespace App\Support\Sse;

use Illuminate\Support\Facades\DB;

/**
 * Detects revision advances by re-reading the committed `tournaments.revision` column on a short
 * cadence. This is the zero-infrastructure default and preserves the original stream behaviour:
 * a change is observed within one poll interval, and the caller flushes (and so notices an aborted
 * client) at least once per interval.
 */
final class PollingRevisionChannel implements RevisionChannel
{
    public function __construct(private readonly int $pollMs = 1500) {}

    public function current(int $tournamentId): int
    {
        return (int) DB::table('tournaments')->where('id', $tournamentId)->value('revision');
    }

    public function awaitChange(int $tournamentId, int $knownRevision, int $timeoutMs): ?int
    {
        $revision = $this->current($tournamentId);
        if ($revision > $knownRevision) {
            return $revision;
        }
        $sleepMs = max(0, min($this->pollMs, $timeoutMs));
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        $revision = $this->current($tournamentId);

        return $revision > $knownRevision ? $revision : null;
    }
}
