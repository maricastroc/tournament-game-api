<?php

declare(strict_types=1);

use App\Support\Sse\TournamentStream;

function streamFixture(): TournamentStream
{
    return new TournamentStream(maxSeconds: 45, pollMs: 1500, retryMs: 3000, heartbeatMs: 15000);
}

test('the retry frame advertises the reconnect backoff', function () {
    expect(streamFixture()->retryFrame())->toBe("retry: 3000\n\n");
});

test('an update frame carries the revision as the SSE id and JSON payload', function () {
    $frame = streamFixture()->updateFrame(7, 42, 'sync', 1_700_000_000);

    expect($frame)
        ->toStartWith("id: 42\n")
        ->toContain("event: update\n")
        ->toContain('"tournament_id":7')
        ->toContain('"revision":42')
        ->toContain('"type":"sync"')
        ->toContain('"ts":1700000000')
        ->toEndWith("\n\n");
});

test('the heartbeat is an inert SSE comment', function () {
    expect(streamFixture()->heartbeatFrame())->toBe(": ping\n\n");
});
