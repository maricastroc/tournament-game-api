<?php

declare(strict_types=1);

use App\Models\Group;
use App\Models\Stage;
use Database\Seeders\TournamentDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('o seeder de demo popula um torneio navegável de ponta a ponta', function () {
    $this->seed(TournamentDemoSeeder::class);

    // o organizador tem credenciais conhecidas (para testar os endpoints protegidos)
    $this->postJson('/api/login', ['email' => 'demo@bracket.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonStructure(['token']);

    // classificação de grupo (leitura pública)
    $groupA = Group::where('name', 'A')->firstOrFail();
    $this->getJson("/api/groups/{$groupA->id}/standings")
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.team.name', 'Brasil')  // 1º por saldo sobre o Japão
        ->assertJsonPath('data.0.qualified', true)
        ->assertJsonPath('data.3.team.name', 'Marrocos');

    // chaveamento derivado: a semifinal já herdou os vencedores das quartas
    $knockout = Stage::where('type', 'knockout')->firstOrFail();
    $this->getJson("/api/stages/{$knockout->id}/bracket")
        ->assertOk()
        ->assertJsonCount(7, 'data.ties')
        ->assertJsonPath('data.ties.4.home.name', 'Brasil')   // SF1 = vencedor QF1
        ->assertJsonPath('data.ties.4.away.name', 'Espanha')  // SF1 = vencedor QF2 (nos pênaltis)
        ->assertJsonPath('data.ties.4.status', 'ready')
        ->assertJsonPath('data.champion', null);
});
