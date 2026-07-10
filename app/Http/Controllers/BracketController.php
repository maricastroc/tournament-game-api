<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tournament\ResolveBracket;
use App\Http\Resources\BracketResource;
use App\Models\Stage;

final class BracketController extends Controller
{
    /** Leitura pública: o chaveamento resolvido de uma fase de mata-mata. */
    public function show(Stage $stage, ResolveBracket $bracket): BracketResource
    {
        return new BracketResource($bracket->for($stage));
    }
}
