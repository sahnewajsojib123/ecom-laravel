<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Product extends Model { protected $guarded=[]; protected $casts=['active'=>'boolean','featured'=>'boolean']; public function category(){return $this->belongsTo(Category::class);} public function inventories(){return $this->hasMany(Inventory::class);} }
