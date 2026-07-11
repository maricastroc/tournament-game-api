<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tournament\ProjectScenario;
use App\Http\Requests\ProjectScenarioRequest;
use App\Http\Resources\ScenarioResource;
use App\Models\Tournament;

final class ScenarioController extends Controller
{
    public function store(
        ProjectScenarioRequest $request,
        Tournament $tournament,
        ProjectScenario $project,
    ): ScenarioResource {
        return new ScenarioResource($project->for($tournament, $request->overlay()));
    }
}
