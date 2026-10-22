<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Banner extends Model { protected $guarded=[]; protected $casts=['active'=>'boolean']; public function product(){return $this->belongsTo(Product::class);} }
