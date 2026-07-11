<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tournament\AddTeams;
use App\Actions\Tournament\UpdateTeam;
use App\Http\Requests\AddTeamsRequest;
use App\Http\Requests\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class TeamController extends Controller
{
    /** Adds teams to the tournament, in batch. Owner only. */
    public function store(AddTeamsRequest $request, Tournament $tournament, AddTeams $action): JsonResponse
    {
        Gate::authorize('manage', $tournament);

        $teams = $action->handle($tournament, $request->teams());

        return TeamResource::collection($teams)->response()->setStatusCode(201);
    }

    /** Renames a team / updates its flag. Owner only. */
    public function update(
        UpdateTeamRequest $request,
        Tournament $tournament,
        Team $team,
        UpdateTeam $action,
    ): TeamResource {
        Gate::authorize('manage', $tournament);

        abort_unless($team->tournament_id === $tournament->id, 404);

        $action->handle($team, $request->editableData());

        return new TeamResource($team);
    }
}
