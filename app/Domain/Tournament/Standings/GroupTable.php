<?php

declare(strict_types=1);

namespace App\Domain\Tournament\Standings;

use App\Domain\Tournament\Input\MatchResult;
use App\Domain\Tournament\Input\TeamRef;

/**
 * The pure group standings engine.
 *
 * No Eloquent, no Illuminate, no I/O: it takes DTOs and returns value objects.
 * The standings are a PROJECTION of the matches — never a mutable state — so
 * "I edited a result and the table reordered" is just recomputing, not syncing.
 */
final class GroupTable
{
    /**
     * @param  TeamRef[]  $teams
     * @param  MatchResult[]  $matches  only finished matches
     * @return Standing[] ordered, with position (1..n) and qualified filled in
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
     * Folds the matches into one row per team. Reused both for the whole group
     * and for the head-to-head mini-tables.
     *
     * @param  TeamRef[]  $teams
     * @param  MatchResult[]  $matches
     * @return array<int, Standing> indexed by team id, in input order
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
            if (! isset($acc[$match->homeTeamId], $acc[$match->awayTeamId])) {
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
     * Orders by applying the chain of criteria. Where a criterion leaves a group of
     * teams tied, it recurses with the remaining criteria — including head-to-head,
     * which rebuilds a mini-table using only the games among those teams.
     *
     * @param  Standing[]  $standings
     * @param  MatchResult[]  $matches
     * @param  Criterion[]  $criteria
     * @return Standing[]
     */
    private static function order(array $standings, array $matches, array $criteria): array
    {
        $list = array_values($standings);

        if (count($list) <= 1 || $criteria === []) {
            return $list;
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
     * Head-to-head: among the still-tied teams, build a mini-league using only
     * the games between them and reorder by points/goal difference/goals for of that slice.
     * Any team still tied even in that mini-league falls through to the following criteria.
     *
     * @param  Standing[]  $tied
     * @param  MatchResult[]  $matches
     * @param  Criterion[]  $rest  criteria after HeadToHead
     * @return Standing[]
     */
    private static function resolveHeadToHead(array $tied, array $matches, array $rest): array
    {
        $refs = array_map(static fn (Standing $s) => $s->team, $tied);
        $intra = self::matchesAmong($tied, $matches);
        $mini = self::accumulate($refs, $intra);

        $original = [];
        foreach ($tied as $standing) {
            $original[$standing->team->id] = $standing;
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
     * Groups into ordered "buckets" (desc) by one or more scalar criteria.
     * Each bucket gathers the teams equal on ALL the given scalars.
     *
     * @param  Standing[]  $standings
     * @param  Criterion[]  $scalars
     * @return Standing[][]
     */
    private static function bucketsByScalars(array $standings, array $scalars): array
    {
        $list = array_values($standings);

        usort($list, static function (Standing $x, Standing $y) use ($scalars): int {
            foreach ($scalars as $scalar) {
                $delta = self::scalar($y, $scalar) <=> self::scalar($x, $scalar);
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
     * @param  Standing[]  $standings
     * @param  MatchResult[]  $matches
     * @return MatchResult[] only the matches where both teams are in the slice
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
            Criterion::HeadToHead => 0,
        };
    }
}
