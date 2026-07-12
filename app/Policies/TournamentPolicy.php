<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Laravel\Sanctum\PersonalAccessToken;

final class TournamentPolicy
{
    /**
     * Denials carry a machine-readable reason (demo_template / not_owner /
     * demo_expired) so the client can tell "you don't own this" apart from
     * "your demo session lapsed" and offer the right next step.
     */
    public function manage(User $user, Tournament $tournament): Response
    {
        if ($tournament->is_demo_template) {
            return Response::deny('This demo tournament is read-only.', 'demo_template');
        }

        if ($tournament->isDemoSandbox()) {
            $token = $user->currentAccessToken();
            $ownsSandbox = $token instanceof PersonalAccessToken
                && (int) $token->getKey() === (int) $tournament->demo_token_id;

            if (! $ownsSandbox) {
                return Response::deny('This sandbox belongs to another session.', 'not_owner');
            }

            if ($tournament->demoExpired()) {
                return Response::deny('Your demo session has expired.', 'demo_expired');
            }

            return Response::allow();
        }

        return $tournament->user_id === $user->id
            ? Response::allow()
            : Response::deny('Only the tournament organizer can save results.', 'not_owner');
    }
}
