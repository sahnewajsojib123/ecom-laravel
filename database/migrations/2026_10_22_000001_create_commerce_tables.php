<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
 Schema::table('users',fn(Blueprint $t)=>$t->boolean('is_admin')->default(false));
 Schema::create('outlets',function(Blueprint $t){$t->id();$t->string('name');$t->string('address');$t->string('phone');$t->boolean('active')->default(true);$t->timestamps();});
 Schema::create('categories',function(Blueprint $t){$t->id();$t->string('name')->unique();$t->string('icon')->default('smartphone');$t->timestamps();});
 Schema::create('products',function(Blueprint $t){$t->id();$t->foreignId('category_id')->constrained()->restrictOnDelete();$t->string('name');$t->string('sku')->unique();$t->string('brand');$t->text('description');$t->text('image');$t->boolean('featured')->default(false);$t->boolean('active')->default(true);$t->timestamps();});
 Schema::create('inventories',function(Blueprint $t){$t->id();$t->foreignId('product_id')->constrained()->restrictOnDelete();$t->foreignId('outlet_id')->constrained()->restrictOnDelete();$t->unsignedBigInteger('price');$t->unsignedBigInteger('compare_price')->nullable();$t->unsignedInteger('quantity')->default(0);$t->unique(['product_id','outlet_id']);$t->timestamps();});
 Schema::create('banners',function(Blueprint $t){$t->id();$t->string('eyebrow');$t->string('title');$t->string('subtitle');$t->string('button_text');$t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();$t->string('background')->default('#edf2ec');$t->boolean('active')->default(true);$t->timestamps();});
 Schema::create('settings',function(Blueprint $t){$t->id();$t->string('key')->unique();$t->text('value');});
 Schema::create('orders',function(Blueprint $t){$t->id();$t->uuid('reference')->unique();$t->uuid('idempotency_key')->unique();$t->foreignId('outlet_id')->constrained()->restrictOnDelete();$t->string('customer_name');$t->string('phone');$t->string('email')->nullable();$t->text('address');$t->string('fulfillment');$t->string('payment_method')->default('cod');$t->string('status')->default('pending');$t->unsignedBigInteger('subtotal');$t->unsignedBigInteger('delivery_fee');$t->unsignedBigInteger('total');$t->timestamps();});
 Schema::create('order_items',function(Blueprint $t){$t->id();$t->foreignId('order_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained()->restrictOnDelete();$t->string('name');$t->unsignedInteger('quantity');$t->unsignedBigInteger('unit_price');});
 }
 public function down(): void {foreach(['order_items','orders','settings','banners','inventories','products','categories','outlets'] as $table)Schema::dropIfExists($table);Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('is_admin'));}
};
