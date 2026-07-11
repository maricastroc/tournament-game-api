<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('renaming a tournament requires authentication', function () {
    $t = Tournament::create(['user_id' => User::factory()->create()->id, 'name' => 'Old name']);

    $this->patchJson("/api/tournaments/{$t->id}", ['name' => 'New name'])->assertUnauthorized();
});

test('a non-owner cannot rename a tournament', function () {
    $t = Tournament::create(['user_id' => User::factory()->create()->id, 'name' => 'Old name']);
    Sanctum::actingAs(User::factory()->create());

    $this->patchJson("/api/tournaments/{$t->id}", ['name' => 'New name'])->assertForbidden();

    expect($t->fresh()->name)->toBe('Old name');
});

test('the owner renames the tournament', function () {
    $owner = User::factory()->create();
    $t = Tournament::create(['user_id' => $owner->id, 'name' => 'Old name']);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/tournaments/{$t->id}", ['name' => 'Copa Atlas 2026'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Copa Atlas 2026');

    expect($t->fresh()->name)->toBe('Copa Atlas 2026');
});

test('an empty tournament name is rejected', function () {
    $owner = User::factory()->create();
    $t = Tournament::create(['user_id' => $owner->id, 'name' => 'Old name']);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/tournaments/{$t->id}", ['name' => 'a'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('the owner renames a team and updates its flag', function () {
    $owner = User::factory()->create();
    $t = Tournament::create(['user_id' => $owner->id, 'name' => 'Cup']);
    $team = Team::create(['tournament_id' => $t->id, 'name' => 'Brazil', 'code' => 'BRA', 'flag' => '🇧🇷']);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/tournaments/{$t->id}/teams/{$team->id}", ['name' => 'Brasil', 'flag' => '🟢'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Brasil')
        ->assertJsonPath('data.flag', '🟢');

    $team->refresh();
    expect($team->name)->toBe('Brasil')
        ->and($team->flag)->toBe('🟢')
        ->and($team->code)->toBe('BRA');
});

test('a non-owner cannot edit a team', function () {
    $t = Tournament::create(['user_id' => User::factory()->create()->id, 'name' => 'Cup']);
    $team = Team::create(['tournament_id' => $t->id, 'name' => 'Brazil']);
    Sanctum::actingAs(User::factory()->create());

    $this->patchJson("/api/tournaments/{$t->id}/teams/{$team->id}", ['name' => 'Hacked'])->assertForbidden();

    expect($team->fresh()->name)->toBe('Brazil');
});

test('editing a team that belongs to another tournament returns 404', function () {
    $owner = User::factory()->create();
    $t = Tournament::create(['user_id' => $owner->id, 'name' => 'Cup']);
    $other = Tournament::create(['user_id' => $owner->id, 'name' => 'Other cup']);
    $team = Team::create(['tournament_id' => $other->id, 'name' => 'Brazil']);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/tournaments/{$t->id}/teams/{$team->id}", ['name' => 'X'])->assertNotFound();
});

test('the detail resource flags can_manage for the owner only', function () {
    $owner = User::factory()->create();
    $t = Tournament::create(['user_id' => $owner->id, 'name' => 'Cup']);

    $this->getJson("/api/tournaments/{$t->id}")->assertOk()->assertJsonPath('data.can_manage', false);

    Sanctum::actingAs($owner);
    $this->getJson("/api/tournaments/{$t->id}")->assertOk()->assertJsonPath('data.can_manage', true);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/tournaments/{$t->id}")->assertOk()->assertJsonPath('data.can_manage', false);
});
