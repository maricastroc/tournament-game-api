<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Tournament\ScenarioOverlay;
use Illuminate\Foundation\Http\FormRequest;

final class ProjectScenarioRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'results' => ['present', 'array'],
            'results.*.fixture_id' => ['required', 'integer'],
            'results.*.home_score' => ['required', 'integer', 'min:0', 'max:99'],
            'results.*.away_score' => ['required', 'integer', 'min:0', 'max:99'],
            'results.*.home_penalties' => ['nullable', 'integer', 'min:0', 'max:99'],
            'results.*.away_penalties' => ['nullable', 'integer', 'min:0', 'max:99'],
        ];
    }

    public function overlay(): ScenarioOverlay
    {
        /** @var array<int, array{fixture_id: int, home_score: int, away_score: int, home_penalties?: ?int, away_penalties?: ?int}> $rows */
        $rows = $this->input('results', []);

        return ScenarioOverlay::fromRows($rows);
    }
}
