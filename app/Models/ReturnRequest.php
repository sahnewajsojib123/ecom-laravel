<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'restocked' => 'boolean'];
    }
}
