<?php

declare(strict_types=1);

return [
    'driver' => env('SSE_DRIVER', 'poll'),

    'redis_connection' => env('SSE_REDIS_CONNECTION', 'default'),

    'max_seconds' => (int) env('SSE_MAX_SECONDS', 45),

    'poll_ms' => (int) env('SSE_POLL_MS', 1500),

    'retry_ms' => (int) env('SSE_RETRY_MS', 3000),

    'heartbeat_ms' => (int) env('SSE_HEARTBEAT_MS', 15000),
];
