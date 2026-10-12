<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A training example for the LET readiness tree that was brought in from a
 * file rather than earned inside this system (see `let:import-training-data`).
 */
class LetTrainingRecord extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'features',
        'result',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
        ];
    }
}
