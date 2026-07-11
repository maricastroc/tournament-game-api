<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

use App\Models\Tournament;

final class UpdateTournament
{
    public function handle(Tournament $tournament, string $name): Tournament
    {
        $tournament->update(['name' => $name]);

        return $tournament;
    }
}
