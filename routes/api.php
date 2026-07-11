<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BracketController;
use App\Http\Controllers\GroupStageController;
use App\Http\Controllers\KnockoutController;
use App\Http\Controllers\MatchResultController;
use App\Http\Controllers\ScenarioController;
use App\Http\Controllers\StandingsController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/groups/{group}/standings', [StandingsController::class, 'show']);
Route::get('/stages/{stage}/bracket', [BracketController::class, 'show']);
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show']);
Route::post('/tournaments/{tournament}/scenario', [ScenarioController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/matches/{fixture}/result', [MatchResultController::class, 'update']);

    Route::get('/tournaments', [TournamentController::class, 'index']);
    Route::post('/tournaments', [TournamentController::class, 'store']);
    Route::delete('/tournaments/{tournament}', [TournamentController::class, 'destroy']);
    Route::post('/tournaments/{tournament}/teams', [TeamController::class, 'store']);
    Route::post('/tournaments/{tournament}/group-stage', [GroupStageController::class, 'store']);
    Route::post('/tournaments/{tournament}/knockout', [KnockoutController::class, 'store']);
});
