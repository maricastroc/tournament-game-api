<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tournament\CreateTournament;
use App\Actions\Tournament\UpdateTournament;
use App\Http\Requests\CreateTournamentRequest;
use App\Http\Requests\UpdateTournamentRequest;
use App\Http\Resources\TournamentDetailResource;
use App\Http\Resources\TournamentResource;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class TournamentController extends Controller
{
    /** Lists the tournaments of the authenticated organizer. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tournaments = Tournament::query()
            ->where('user_id', $request->user()->id)
            ->withCount(['teams', 'stages'])
            ->latest()
            ->get();

        return TournamentResource::collection($tournaments);
    }

    /** Creates a tournament (draft). */
    public function store(CreateTournamentRequest $request, CreateTournament $action): JsonResponse
    {
        $tournament = $action->handle($request->user(), $request->tournamentName());

        return (new TournamentResource($tournament->loadCount(['teams', 'stages'])))
            ->response()
            ->setStatusCode(201);
    }

    /** Renames the tournament. Owner only. */
    public function update(
        UpdateTournamentRequest $request,
        Tournament $tournament,
        UpdateTournament $action,
    ): TournamentResource {
        Gate::authorize('manage', $tournament);

        $action->handle($tournament, $request->tournamentName());

        return new TournamentResource($tournament->loadCount(['teams', 'stages']));
    }

    /** The full view of the tournament — structure + matches (with version). Public (fan view). */
    public function show(Tournament $tournament): TournamentDetailResource
    {
        return new TournamentDetailResource($tournament->loadFullDetail());
    }

    /** Removes the tournament (cascade handles teams/stages/matches). Owner only. */
    public function destroy(Tournament $tournament): Response
    {
        Gate::authorize('manage', $tournament);

        $tournament->delete();

        return response()->noContent();
    }
}
