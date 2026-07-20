<?php

declare(strict_types=1);

use App\Models\Tournament;
use App\Models\User;
use App\Support\Sse\PollingRevisionChannel;
use App\Support\Sse\RedisRevisionChannel;
use App\Support\Sse\RevisionChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The RevisionChannel seam: the container picks the transport from config/sse.php: 'driver', and
 * the default polling transport reads the authoritative revision. The redis transport's live
 * subscribe path needs a running Redis and is exercised in a real environment, not here; its
 * write half (PublishTournamentUpdate) is covered in RealtimePublishTest.
 */

test('the container resolves the polling channel by default', function () {
    config(['sse.driver' => 'poll']);

    expect(app(RevisionChannel::class))->toBeInstanceOf(PollingRevisionChannel::class);
});

test('the container resolves the redis channel when the driver is redis', function () {
    config(['sse.driver' => 'redis']);

    expect(app(RevisionChannel::class))->toBeInstanceOf(RedisRevisionChannel::class);
});

test('the polling channel reads the current committed revision', function () {
    $tournament = Tournament::create(['user_id' => User::factory()->create()->id, 'name' => 'Atlas Cup']);
    $tournament->increment('revision');
    $tournament->increment('revision');

    $channel = new PollingRevisionChannel(pollMs: 1000);

    expect($channel->current((int) $tournament->id))->toBe(2);
});

test('the polling channel returns immediately when the revision already advanced', function () {
    $tournament = Tournament::create(['user_id' => User::factory()->create()->id, 'name' => 'Atlas Cup']);
    $tournament->increment('revision');

    $channel = new PollingRevisionChannel(pollMs: 1000);

    expect($channel->awaitChange((int) $tournament->id, 0, 50))->toBe(1);
});

test('the polling channel returns null when nothing changed within the window', function () {
    $tournament = Tournament::create(['user_id' => User::factory()->create()->id, 'name' => 'Atlas Cup']);
    $tournament->increment('revision');

    $channel = new PollingRevisionChannel(pollMs: 10);

    expect($channel->awaitChange((int) $tournament->id, 1, 30))->toBeNull();
});
