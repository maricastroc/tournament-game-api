<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

final class ScenarioOverlay
{
    /** @param array<int, ScenarioResult> $byFixture */
    private function __construct(private readonly array $byFixture) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<int, array{fixture_id: int, home_score: int, away_score: int, home_penalties?: ?int, away_penalties?: ?int}>  $rows
     */
    public static function fromRows(array $rows): self
    {
        $byFixture = [];
        foreach ($rows as $row) {
            $byFixture[(int) $row['fixture_id']] = new ScenarioResult(
                (int) $row['home_score'],
                (int) $row['away_score'],
                isset($row['home_penalties']) ? (int) $row['home_penalties'] : null,
                isset($row['away_penalties']) ? (int) $row['away_penalties'] : null,
            );
        }

        return new self($byFixture);
    }

    public function isEmpty(): bool
    {
        return $this->byFixture === [];
    }

    public function for(int $fixtureId): ?ScenarioResult
    {
        return $this->byFixture[$fixtureId] ?? null;
    }
}
