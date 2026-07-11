<?php

declare(strict_types=1);

namespace App\Actions\Tournament;

use App\Models\Team;

final class UpdateTeam
{
    /** @param  array<string, string|null>  $data */
    public function handle(Team $team, array $data): Team
    {
        if ($data !== []) {
            $team->update($data);
        }

        return $team;
    }
}
