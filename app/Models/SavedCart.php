<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedCart extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'consent' => 'boolean', 'recovered_at' => 'datetime', 'reminded_at' => 'datetime'];
    }
}
