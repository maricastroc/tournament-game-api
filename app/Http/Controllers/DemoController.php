<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Demo\ProvisionDemoSandbox;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class DemoController extends Controller
{
    /** The public demo tournament shown to anonymous visitors: the read-only template. */
    public function template(): JsonResponse
    {
        $template = Tournament::query()->where('is_demo_template', true)->first();

        return response()->json(['tournament_id' => $template?->id]);
    }

    /**
     * Resets this session's demo sandbox: drops the current copy and clones a
     * fresh one from the template. One active sandbox per session token.
     */
    public function reset(Request $request, ProvisionDemoSandbox $provision): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->email === config('demo.email'), 403, 'Only the demo account can reset the sandbox.');

        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403);

        $tokenId = (int) $token->getKey();

        Tournament::query()->where('demo_token_id', $tokenId)->get()->each->delete();

        $sandbox = $provision->handle($tokenId);

        abort_if($sandbox === null, 422, 'The demo template is not available.');

        return response()->json(['sandbox_tournament_id' => $sandbox->id]);
    }
}
