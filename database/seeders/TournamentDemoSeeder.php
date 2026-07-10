<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Fixture;
use App\Models\Group;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tie;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Constrói a "Copa Atlas 2026" completa: um organizador de credenciais conhecidas,
 * 4 grupos totalmente disputados e um mata-mata em andamento — para a API ficar rica
 * e navegável num comando. Reexecutável: derruba a demo anterior antes de reconstruir.
 *
 * Credenciais do organizador: demo@bracket.test / password
 */
final class TournamentDemoSeeder extends Seeder
{
    /** Grupos em ordem de força (índice 0 = 1º colocado pelo esquema de placares). */
    private const GROUPS = [
        'A' => [['Brasil', '🇧🇷', 'BRA'], ['Japão', '🇯🇵', 'JPN'], ['Croácia', '🇭🇷', 'CRO'], ['Marrocos', '🇲🇦', 'MAR']],
        'B' => [['Argentina', '🇦🇷', 'ARG'], ['França', '🇫🇷', 'FRA'], ['Senegal', '🇸🇳', 'SEN'], ['Polônia', '🇵🇱', 'POL']],
        'C' => [['Espanha', '🇪🇸', 'ESP'], ['Alemanha', '🇩🇪', 'GER'], ['Uruguai', '🇺🇾', 'URU'], ['Coreia do Sul', '🇰🇷', 'KOR']],
        'D' => [['Portugal', '🇵🇹', 'POR'], ['Países Baixos', '🇳🇱', 'NED'], ['México', '🇲🇽', 'MEX'], ['Itália', '🇮🇹', 'ITA']],
    ];

    /**
     * Placares do returno-único de cada grupo, por par de índices (mais forte à esquerda).
     * Garante 1º/2º decididos, com os dois primeiros empatados em pontos (desempate no saldo).
     *
     * @var array<string, array{int, int}>
     */
    private const GROUP_SCORES = [
        '0-1' => [1, 1], // os dois melhores empatam
        '0-2' => [2, 0],
        '0-3' => [3, 0],
        '1-2' => [2, 1],
        '1-3' => [2, 0],
        '2-3' => [1, 0],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            // idempotência: derruba a demo anterior (cascata cuida de times/fases/jogos/chaves)
            Tournament::where('name', 'Copa Atlas 2026')->get()->each->delete();

            $owner = User::updateOrCreate(
                ['email' => 'demo@bracket.test'],
                ['name' => 'Organizador Demo', 'password' => Hash::make('password')],
            );

            $tournament = Tournament::create([
                'user_id' => $owner->id,
                'name' => 'Copa Atlas 2026',
                'status' => 'active',
            ]);

            $groupStage = Stage::create([
                'tournament_id' => $tournament->id,
                'type' => 'group',
                'name' => 'Fase de grupos',
                'position' => 1,
            ]);

            foreach (self::GROUPS as $name => $definitions) {
                $group = Group::create(['stage_id' => $groupStage->id, 'name' => $name, 'qualify_count' => 2]);

                $teams = array_map(fn (array $d) => Team::create([
                    'tournament_id' => $tournament->id,
                    'name' => $d[0],
                    'flag' => $d[1],
                    'code' => $d[2],
                ]), $definitions);

                $group->teams()->attach(collect($teams)->pluck('id'));
                $this->playGroup($tournament, $groupStage, $group, $teams);
            }

            $this->buildKnockout($tournament);
        });
    }

    /** @param  Team[]  $teams  em ordem de força */
    private function playGroup(Tournament $tournament, Stage $stage, Group $group, array $teams): void
    {
        foreach (self::GROUP_SCORES as $pair => [$homeScore, $awayScore]) {
            [$strong, $weak] = array_map('intval', explode('-', $pair));

            Fixture::create([
                'tournament_id' => $tournament->id,
                'stage_id' => $stage->id,
                'group_id' => $group->id,
                'home_team_id' => $teams[$strong]->id,
                'away_team_id' => $teams[$weak]->id,
                'home_score' => $homeScore,
                'away_score' => $awayScore,
                'status' => 'finished',
            ]);
        }
    }

    private function buildKnockout(Tournament $tournament): void
    {
        $stage = Stage::create([
            'tournament_id' => $tournament->id,
            'type' => 'knockout',
            'name' => 'Mata-mata',
            'position' => 2,
        ]);

        $tie = fn (int $round, int $slot, string $home, string $away) => Tie::create([
            'stage_id' => $stage->id,
            'round' => $round,
            'slot' => $slot,
            'home_source' => $home,
            'away_source' => $away,
        ]);

        // Quartas (cruzando os grupos), semis e final
        $qf1 = $tie(1, 1, 'seed:A1', 'seed:B2'); // Brasil × França
        $qf2 = $tie(1, 2, 'seed:C1', 'seed:D2'); // Espanha × Países Baixos
        $qf3 = $tie(1, 3, 'seed:B1', 'seed:A2'); // Argentina × Japão
        $qf4 = $tie(1, 4, 'seed:D1', 'seed:C2'); // Portugal × Alemanha
        $sf1 = $tie(2, 1, "winner:{$qf1->id}", "winner:{$qf2->id}");
        $sf2 = $tie(2, 2, "winner:{$qf3->id}", "winner:{$qf4->id}");
        $final = $tie(3, 1, "winner:{$sf1->id}", "winner:{$sf2->id}");

        // Duas quartas já decididas (uma nos pênaltis); o resto agendado.
        $this->knockoutFixture($tournament, $stage, $qf1, 2, 1);          // Brasil vence
        $this->knockoutFixture($tournament, $stage, $qf2, 1, 1, 4, 2);    // Espanha nos pênaltis
        foreach ([$qf3, $qf4, $sf1, $sf2, $final] as $pending) {
            $this->knockoutFixture($tournament, $stage, $pending);
        }
    }

    private function knockoutFixture(
        Tournament $tournament,
        Stage $stage,
        Tie $tie,
        ?int $homeScore = null,
        ?int $awayScore = null,
        ?int $homePenalties = null,
        ?int $awayPenalties = null,
    ): void {
        Fixture::create([
            'tournament_id' => $tournament->id,
            'stage_id' => $stage->id,
            'tie_id' => $tie->id,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'home_penalties' => $homePenalties,
            'away_penalties' => $awayPenalties,
            'status' => $homeScore === null ? 'scheduled' : 'finished',
        ]);
    }
}
