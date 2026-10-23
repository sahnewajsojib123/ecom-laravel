<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
