<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tiebreak' => 'array',
    ];

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class);
    }
}
