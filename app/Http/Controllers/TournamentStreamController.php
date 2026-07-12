<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Support\Sse\TournamentStream;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, per-tournament Server-Sent Events endpoint that spectators subscribe to.
 *
 * The connection stays open and polls the tournament's `revision` (one indexed read). When it
 * advances — i.e. a result save committed — it pushes a small `update` frame and the client
 * refetches the authoritative snapshot. It exposes only already-public data (id + revision), so
 * no auth is required, matching the public standings/bracket reads.
 *
 * NOTE: each open connection occupies one PHP worker for its lifetime. Under `php artisan serve`
 * that means the server must run with PHP_CLI_SERVER_WORKERS > 1, otherwise a single held stream
 * blocks every other request (including the organizer's save). See config/sse.php + README.
 */
final class TournamentStreamController extends Controller
{
    public function stream(Tournament $tournament): StreamedResponse
    {
        $stream = TournamentStream::fromConfig();
        $tournamentId = (int) $tournament->id;

        return new StreamedResponse(
            fn () => $this->pump($stream, $tournamentId),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function pump(TournamentStream $stream, int $tournamentId): void
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        echo $stream->retryFrame();

        $lastRevision = $this->currentRevision($tournamentId);
        echo $stream->updateFrame($tournamentId, $lastRevision, 'sync', time());
        flush();

        $deadline = time() + $stream->maxSeconds;
        $lastBeatAt = time();

        while (time() < $deadline && ! connection_aborted()) {
            usleep($stream->pollMs * 1000);

            $revision = $this->currentRevision($tournamentId);

            if ($revision > $lastRevision) {
                echo $stream->updateFrame($tournamentId, $revision, 'sync', time());
                $lastRevision = $revision;
                $lastBeatAt = time();
            } elseif ((time() - $lastBeatAt) * 1000 >= $stream->heartbeatMs) {
                echo $stream->heartbeatFrame();
                $lastBeatAt = time();
            }

            flush();
        }
    }

    private function currentRevision(int $tournamentId): int
    {
        return (int) DB::table('tournaments')->where('id', $tournamentId)->value('revision');
    }
}
