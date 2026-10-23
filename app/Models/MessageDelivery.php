<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
