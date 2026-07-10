<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tournament\ConfirmKnockoutResult;
use App\Actions\Tournament\ConfirmMatchResult;
use App\Http\Requests\ConfirmMatchResultRequest;
use App\Http\Resources\BracketResource;
use App\Http\Resources\StandingResource;
use App\Models\Fixture;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Gate;

final class MatchResultController extends Controller
{
    /**
     * Lança/edita o resultado de um jogo e devolve a projeção recalculada:
     * jogo de grupo -> classificação; jogo de mata-mata -> chaveamento.
     *
     * Só o organizador do torneio pode; conflito de versão vira 409 (StaleResultException).
     */
    public function update(
        ConfirmMatchResultRequest $request,
        Fixture $fixture,
        ConfirmMatchResult $group,
        ConfirmKnockoutResult $knockout,
    ): Responsable {
        Gate::authorize('manage', $fixture->tournament);

        if ($fixture->tie_id !== null) {
            return new BracketResource($knockout->handle(
                $fixture,
                $request->homeScore(),
                $request->awayScore(),
                $request->expectedVersion(),
                $request->homePenalties(),
                $request->awayPenalties(),
            ));
        }

        return StandingResource::collection($group->handle(
            $fixture,
            $request->homeScore(),
            $request->awayScore(),
            $request->expectedVersion(),
        ));
    }
}
