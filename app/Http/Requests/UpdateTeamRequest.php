<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTeamRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'flag' => ['sometimes', 'nullable', 'string', 'max:16'],
        ];
    }

    /**
     * Only the fields present in the request, so a partial edit never clears the others.
     *
     * @return array<string, string|null>
     */
    public function editableData(): array
    {
        $out = [];
        if ($this->has('name')) {
            $out['name'] = trim((string) $this->string('name'));
        }
        if ($this->has('code')) {
            $out['code'] = $this->filled('code') ? (string) $this->string('code') : null;
        }
        if ($this->has('flag')) {
            $out['flag'] = $this->filled('flag') ? (string) $this->string('flag') : null;
        }

        return $out;
    }
}
