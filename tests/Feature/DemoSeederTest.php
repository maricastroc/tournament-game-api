<?php

declare(strict_types=1);

use App\Models\Group;
use App\Models\Stage;
use Database\Seeders\TournamentDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the demo seeder populates a browsable end-to-end tournament', function () {
    $this->seed(TournamentDemoSeeder::class);

    $this->postJson('/api/login', ['email' => 'demo@bracket.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonStructure(['token']);

    $groupA = Group::where('name', 'A')->firstOrFail();
    $this->getJson("/api/groups/{$groupA->id}/standings")
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.team.name', 'Brazil')
        ->assertJsonPath('data.0.qualified', true)
        ->assertJsonPath('data.3.team.name', 'Morocco');

    $knockout = Stage::where('type', 'knockout')->firstOrFail();
    $this->getJson("/api/stages/{$knockout->id}/bracket")
        ->assertOk()
        ->assertJsonCount(7, 'data.ties')
        ->assertJsonPath('data.ties.4.home.name', 'Brazil')
        ->assertJsonPath('data.ties.4.away.name', 'Spain')
        ->assertJsonPath('data.ties.4.status', 'ready')
        ->assertJsonPath('data.champion', null);
});
