<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'email', 'address'];

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^01[0-9]{9}$/', $digits)) {
            $digits = '88'.$digits;
        }

        return $digits;
    }

    public function setPhoneAttribute(string $phone): void
    {
        $this->attributes['phone'] = self::normalizePhone($phone);
    }

    public function getFirstNameAttribute(): string
    {
        return explode(' ', trim($this->name), 2)[0];
    }

    public function getLastNameAttribute(): string
    {
        return explode(' ', trim($this->name), 2)[1] ?? '';
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
