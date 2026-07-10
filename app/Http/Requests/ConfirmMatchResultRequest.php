<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmMatchResultRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'home_score' => ['required', 'integer', 'min:0', 'max:99'],
            'away_score' => ['required', 'integer', 'min:0', 'max:99'],
            'expected_version' => ['required', 'integer', 'min:0'],
            // pênaltis: só fazem sentido no mata-mata; opcionais e ignorados nos grupos
            'home_penalties' => ['nullable', 'integer', 'min:0', 'max:99'],
            'away_penalties' => ['nullable', 'integer', 'min:0', 'max:99'],
        ];
    }

    public function homeScore(): int
    {
        return (int) $this->integer('home_score');
    }

    public function awayScore(): int
    {
        return (int) $this->integer('away_score');
    }

    public function expectedVersion(): int
    {
        return (int) $this->integer('expected_version');
    }

    public function homePenalties(): ?int
    {
        return $this->has('home_penalties') && $this->input('home_penalties') !== null
            ? (int) $this->integer('home_penalties')
            : null;
    }

    public function awayPenalties(): ?int
    {
        return $this->has('away_penalties') && $this->input('away_penalties') !== null
            ? (int) $this->integer('away_penalties')
            : null;
    }
}
