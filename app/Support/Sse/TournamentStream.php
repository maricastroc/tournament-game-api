<?php

declare(strict_types=1);

namespace App\Support\Sse;

/**
 * Formats the Server-Sent Events frames for a tournament's spectator stream and holds the
 * timing knobs. Deliberately free of I/O so the frame formatting is unit-testable; the
 * controller owns the connection loop and the (cheap) revision reads.
 */
final class TournamentStream
{
    public function __construct(
        public readonly int $maxSeconds,
        public readonly int $pollMs,
        public readonly int $retryMs,
        public readonly int $heartbeatMs,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxSeconds: (int) config('sse.max_seconds', 45),
            pollMs: (int) config('sse.poll_ms', 1500),
            retryMs: (int) config('sse.retry_ms', 3000),
            heartbeatMs: (int) config('sse.heartbeat_ms', 15000),
        );
    }

    /** Tells EventSource how long to wait before reconnecting once the stream closes. */
    public function retryFrame(): string
    {
        return "retry: {$this->retryMs}\n\n";
    }

    /**
     * An `update` event carrying the monotonic revision. The client ignores it unless the
     * revision is newer than what it already showed; otherwise it refetches the snapshot.
     * The SSE `id:` doubles as the Last-Event-ID the browser echoes on reconnect.
     */
    public function updateFrame(int $tournamentId, int $revision, string $type, int $timestamp): string
    {
        $data = json_encode([
            'tournament_id' => $tournamentId,
            'revision' => $revision,
            'type' => $type,
            'ts' => $timestamp,
        ], JSON_THROW_ON_ERROR);

        return "id: {$revision}\nevent: update\ndata: {$data}\n\n";
    }

    /** A comment line — ignored by EventSource, but keeps proxies from idling the socket out. */
    public function heartbeatFrame(): string
    {
        return ": ping\n\n";
    }
}
