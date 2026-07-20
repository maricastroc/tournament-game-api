<?php

declare(strict_types=1);

namespace App\Support\Sse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Detects revision advances by blocking on a Redis pub/sub channel that PublishTournamentUpdate
 * feeds whenever a save commits. A committed result therefore reaches every open stream in
 * ~milliseconds instead of on the next poll — this is the "real push" transport.
 *
 * The authoritative value still comes from the DB (`current()`): pub/sub is fire-and-forget with no
 * backlog, so the channel is only a *nudge*. Every awaitChange re-reads the snapshot around the
 * subscribe, which both seeds the wait and closes the tiny race where a publish lands between the
 * snapshot read and the subscribe. Any transport failure degrades to a single snapshot read — it
 * never throws into the stream loop, and a dropped client simply re-syncs from state on reconnect.
 *
 * NOTE: this path requires a reachable Redis (see config/sse.php: SSE_DRIVER=redis) and the phpredis
 * client. The default driver is `poll`, which needs neither.
 */
final class RedisRevisionChannel implements RevisionChannel
{
    public function __construct(private readonly string $connection = 'default') {}

    public function current(int $tournamentId): int
    {
        return (int) DB::table('tournaments')->where('id', $tournamentId)->value('revision');
    }

    public function awaitChange(int $tournamentId, int $knownRevision, int $timeoutMs): ?int
    {
        $current = $this->current($tournamentId);
        if ($current > $knownRevision) {
            return $current;
        }

        $observed = null;

        try {
            $client = Redis::connection($this->connection);
            $client->setOption(\Redis::OPT_READ_TIMEOUT, max(0.05, $timeoutMs / 1000));
            $client->subscribe([$this->channelFor($tournamentId)], function ($redis, $channel, $message) use (&$observed): void {
                $decoded = json_decode((string) $message, true);
                if (is_array($decoded) && isset($decoded['revision'])) {
                    $observed = (int) $decoded['revision'];
                }

                $redis->close();
            });
        } catch (Throwable) {
            //
        }

        $after = $this->current($tournamentId);
        if ($after > $knownRevision) {
            return $after;
        }

        return $observed !== null && $observed > $knownRevision ? $observed : null;
    }

    private function channelFor(int $tournamentId): string
    {
        return 'tournament.'.$tournamentId;
    }
}
