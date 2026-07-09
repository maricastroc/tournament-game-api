<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Standings;

use App\Domain\Tournament\Input\MatchResult;
use App\Domain\Tournament\Input\TeamRef;

/**
 * A engine pura de classificação de grupo.
 *
 * Sem Eloquent, sem Illuminate, sem I/O: recebe DTOs, devolve value objects.
 * A classificação é uma PROJEÇÃO das partidas — nunca um estado mutável — então
 * "editei um resultado e a tabela reordenou" é só recomputar, não sincronizar.
 */
final class GroupTable
{
    /**
     * @param TeamRef[]     $teams
     * @param MatchResult[] $matches  apenas partidas encerradas
     * @return Standing[]   ordenado, com position (1..n) e qualified preenchidos
     */
    public static function compute(array $teams, array $matches, TiebreakRules $rules, int $qualify = 2): array
    {
        $standings = self::accumulate($teams, $matches);
        $ordered = self::order($standings, $matches, $rules->criteria);

        $ranked = [];
        foreach (array_values($ordered) as $i => $standing) {
            $ranked[] = $standing->withRank($i + 1, $i < $qualify);
        }

        return $ranked;
    }

    /**
     * Dobra as partidas em uma linha por time. Reutilizada tanto para o grupo
     * inteiro quanto para as mini-tabelas do confronto direto.
     *
     * @param TeamRef[]     $teams
     * @param MatchResult[] $matches
     * @return array<int, Standing>  indexado por id do time, na ordem de entrada
     */
    private static function accumulate(array $teams, array $matches): array
    {
        $acc = [];
        foreach ($teams as $team) {
            $acc[$team->id] = [
                'team' => $team,
                'p' => 0, 'w' => 0, 'd' => 0, 'l' => 0,
                'gf' => 0, 'ga' => 0, 'pts' => 0, 'form' => [],
            ];
        }

        foreach ($matches as $match) {
            // Ignora partidas cujos times não estão neste conjunto — essencial para
            // que a mini-tabela do confronto direto veja só os jogos entre os empatados.
            if (!isset($acc[$match->homeTeamId], $acc[$match->awayTeamId])) {
                continue;
            }

            $home = &$acc[$match->homeTeamId];
            $away = &$acc[$match->awayTeamId];

            $home['p']++;
            $away['p']++;
            $home['gf'] += $match->homeScore;
            $home['ga'] += $match->awayScore;
            $away['gf'] += $match->awayScore;
            $away['ga'] += $match->homeScore;

            if ($match->homeScore > $match->awayScore) {
                $home['w']++;
                $home['pts'] += 3;
                $home['form'][] = 'W';
                $away['l']++;
                $away['form'][] = 'L';
            } elseif ($match->homeScore < $match->awayScore) {
                $away['w']++;
                $away['pts'] += 3;
                $away['form'][] = 'W';
                $home['l']++;
                $home['form'][] = 'L';
            } else {
                $home['d']++;
                $home['pts']++;
                $home['form'][] = 'D';
                $away['d']++;
                $away['pts']++;
                $away['form'][] = 'D';
            }

            unset($home, $away);
        }

        $standings = [];
        foreach ($acc as $id => $r) {
            $standings[$id] = new Standing(
                $r['team'], $r['p'], $r['w'], $r['d'], $r['l'],
                $r['gf'], $r['ga'], $r['pts'], $r['form'],
            );
        }

        return $standings;
    }

    /**
     * Ordena aplicando a cadeia de critérios. Onde um critério deixa um grupo de
     * times empatado, recorre com os critérios restantes — inclusive o confronto
     * direto, que reconstrói uma mini-tabela só com os jogos entre esses times.
     *
     * @param Standing[]  $standings
     * @param MatchResult[] $matches
     * @param Criterion[] $criteria
     * @return Standing[]
     */
    private static function order(array $standings, array $matches, array $criteria): array
    {
        $list = array_values($standings);

        if (count($list) <= 1 || $criteria === []) {
            return $list; // usort é estável no PHP 8+: empates totais mantêm a ordem de entrada
        }

        $criterion = $criteria[0];
        $rest = array_slice($criteria, 1);

        if ($criterion === Criterion::HeadToHead) {
            return self::resolveHeadToHead($list, $matches, $rest);
        }

        $result = [];
        foreach (self::bucketsByScalars($list, [$criterion]) as $bucket) {
            $result = array_merge(
                $result,
                count($bucket) === 1 ? $bucket : self::order($bucket, $matches, $rest),
            );
        }

        return $result;
    }

    /**
     * Confronto direto: entre os times ainda empatados, monta uma mini-liga só com
     * os jogos entre eles e reordena por pontos/saldo/gols pró desse recorte.
     * Quem continuar empatado até nessa mini-liga cai para os critérios seguintes.
     *
     * @param Standing[]  $tied
     * @param MatchResult[] $matches
     * @param Criterion[] $rest  critérios após o HeadToHead
     * @return Standing[]
     */
    private static function resolveHeadToHead(array $tied, array $matches, array $rest): array
    {
        $refs = array_map(static fn (Standing $s) => $s->team, $tied);
        $intra = self::matchesAmong($tied, $matches);
        $mini = self::accumulate($refs, $intra);

        $original = [];
        foreach ($tied as $standing) {
            $original[$standing->team->id] = $standing; // preserva os números do grupo cheio
        }

        $result = [];
        foreach (self::bucketsByScalars($mini, [Criterion::Points, Criterion::GoalDifference, Criterion::GoalsFor]) as $bucket) {
            $group = array_map(static fn (Standing $m) => $original[$m->team->id], $bucket);
            $result = array_merge(
                $result,
                count($group) === 1 ? $group : self::order($group, $matches, $rest),
            );
        }

        return $result;
    }

    /**
     * Agrupa em "baldes" ordenados (desc) por um ou mais critérios escalares.
     * Cada balde reúne os times iguais em TODOS os escalares dados.
     *
     * @param Standing[]  $standings
     * @param Criterion[] $scalars
     * @return Standing[][]
     */
    private static function bucketsByScalars(array $standings, array $scalars): array
    {
        $list = array_values($standings);

        usort($list, static function (Standing $x, Standing $y) use ($scalars): int {
            foreach ($scalars as $scalar) {
                $delta = self::scalar($y, $scalar) <=> self::scalar($x, $scalar); // desc
                if ($delta !== 0) {
                    return $delta;
                }
            }

            return 0;
        });

        $buckets = [];
        $current = [];
        $previousKey = null;

        foreach ($list as $standing) {
            $key = implode('|', array_map(fn (Criterion $c) => self::scalar($standing, $c), $scalars));
            if ($previousKey !== null && $key !== $previousKey) {
                $buckets[] = $current;
                $current = [];
            }
            $current[] = $standing;
            $previousKey = $key;
        }

        if ($current !== []) {
            $buckets[] = $current;
        }

        return $buckets;
    }

    /**
     * @param Standing[]    $standings
     * @param MatchResult[] $matches
     * @return MatchResult[]  só as partidas em que ambos os times estão no recorte
     */
    private static function matchesAmong(array $standings, array $matches): array
    {
        $ids = [];
        foreach ($standings as $standing) {
            $ids[$standing->team->id] = true;
        }

        return array_values(array_filter(
            $matches,
            static fn (MatchResult $m) => isset($ids[$m->homeTeamId], $ids[$m->awayTeamId]),
        ));
    }

    private static function scalar(Standing $s, Criterion $criterion): int
    {
        return match ($criterion) {
            Criterion::Points => $s->points,
            Criterion::GoalDifference => $s->goalDifference(),
            Criterion::GoalsFor => $s->goalsFor,
            Criterion::Wins => $s->won,
            Criterion::HeadToHead => 0, // não é escalar; tratado em resolveHeadToHead()
        };
    }
}
