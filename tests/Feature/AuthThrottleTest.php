<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Brute-force protection on the unauthenticated credential routes. The `auth` limiter is
 * registered in AppServiceProvider; here we prove it is actually wired to /login and /register
 * and returns a 429 with a Retry-After once the per-(email+IP) budget is spent.
 */

test('login throttles after 5 failed attempts from the same email + ip', function () {
    User::factory()->create([
        'email' => 'victim@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $wrong = ['email' => 'victim@example.com', 'password' => 'not-the-password'];

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', $wrong)->assertStatus(422);
    }

    $this->postJson('/api/login', $wrong)
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

test('register throttles a repeated hammering of the same email', function () {
    $payload = fn (string $suffix = '') => [
        'name' => 'Spammer',
        'email' => 'spammer@example.com',
        'password' => 'password123',
    ];

    $this->postJson('/api/register', $payload())->assertCreated();

    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/register', $payload())->assertStatus(422);
    }

    $this->postJson('/api/register', $payload())
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

test('a legitimate login within the limit is unaffected', function () {
    User::factory()->create([
        'email' => 'player@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $this->postJson('/api/login', [
        'email' => 'player@example.com',
        'password' => 'correct-horse-battery',
    ])->assertOk();
});
