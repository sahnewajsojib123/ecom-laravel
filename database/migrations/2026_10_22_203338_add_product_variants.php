<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('sku')->unique();
            $t->json('options')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('inventories', function (Blueprint $t) {
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $t->string('variant_name')->nullable();
            $t->string('sku')->nullable();
        });
        foreach (DB::table('products')->orderBy('id')->get() as $p) {
            $v = DB::table('product_variants')->insertGetId(['product_id' => $p->id, 'name' => 'Default', 'sku' => $p->sku, 'options' => '{}', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inventories')->where('product_id', $p->id)->update(['variant_id' => $v]);
            DB::table('order_items')->where('product_id', $p->id)->update(['variant_id' => $v, 'variant_name' => 'Default', 'sku' => $p->sku]);
        }
        Schema::table('inventories', function (Blueprint $t) {
            $t->dropUnique(['product_id', 'outlet_id']);
            $t->unsignedBigInteger('variant_id')->nullable(false)->change();
            $t->unique(['variant_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('inventories')->select('product_id', 'outlet_id')->groupBy('product_id', 'outlet_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Multiple variants have outlet inventory. Consolidate them before rolling back to product-only inventory.');
        }
        Schema::table('inventories', function (Blueprint $t) {
            $t->dropUnique(['variant_id', 'outlet_id']);
            $t->dropConstrainedForeignId('variant_id');
            $t->unique(['product_id', 'outlet_id']);
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('variant_id');
            $t->dropColumn(['variant_name', 'sku']);
        });
        Schema::dropIfExists('product_variants');
    }
};
