<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->string('payment_status')->default('unpaid');
            $t->string('fulfillment_status')->default('unfulfilled');
            $t->string('country_code', 2)->default('BD');
            $t->string('district')->nullable();
            $t->string('postal_code')->nullable();
            $t->string('discount_code')->nullable();
            $t->unsignedBigInteger('discount_total')->default(0);
            $t->unsignedBigInteger('tax_total')->default(0);
            $t->unsignedInteger('tax_basis_points')->default(0);
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->foreignId('exchange_order_id')->nullable()->constrained('orders')->restrictOnDelete();
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedInteger('fulfilled_quantity')->default(0);
            $t->unsignedInteger('returned_quantity')->default(0);
        });
        DB::table('order_items')->whereIn('order_id', DB::table('orders')->where('status', 'completed')->select('id'))->update(['fulfilled_quantity' => DB::raw('quantity')]);
        DB::table('orders')->where('status', 'completed')->update(['fulfillment_status' => 'fulfilled']);
        DB::table('orders')->where('status', 'cancelled')->update(['cancelled_at' => DB::raw('updated_at')]);
        Schema::create('discounts', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('type');
            $t->unsignedInteger('value');
            $t->unsignedBigInteger('minimum_subtotal')->default(0);
            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('used_count')->default(0);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('shipping_zones', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('country_code', 2)->default('BD');
            $t->string('district')->nullable();
            $t->unsignedBigInteger('fee');
            $t->unsignedBigInteger('free_above')->nullable();
            $t->unsignedInteger('tax_basis_points')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['country_code', 'district']);
        });
        Schema::create('order_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('action');
            $t->string('actor');
            $t->string('operation_key')->nullable();
            $t->string('request_hash')->nullable();
            $t->json('details');
            $t->timestamps();
            $t->unique(['order_id', 'operation_key']);
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->json('items');
            $t->string('carrier')->nullable();
            $t->string('tracking_number')->nullable();
            $t->text('tracking_url')->nullable();
            $t->string('status')->default('shipped');
            $t->timestamps();
        });
        Schema::create('payment_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->string('type');
            $t->unsignedBigInteger('amount');
            $t->string('method');
            $t->string('reference');
            $t->text('note');
            $t->timestamps();
            $t->unique(['method', 'reference']);
        });
        Schema::create('return_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->json('items');
            $t->text('reason');
            $t->string('status')->default('requested');
            $t->boolean('restocked')->default(false);
            $t->timestamps();
        });
        Schema::create('message_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('recipient');
            $t->string('subject');
            $t->text('body');
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });
        Schema::create('saved_carts', function (Blueprint $t) {
            $t->id();
            $t->string('session_key')->unique();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('email');
            $t->json('items');
            $t->boolean('consent')->default(false);
            $t->timestamp('recovered_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('draft_orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->json('payload');
            $t->unsignedBigInteger('total');
            $t->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('login_codes', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('code_hash');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['draft_orders', 'login_codes', 'saved_carts', 'message_deliveries', 'return_requests', 'payment_records', 'shipments', 'order_events', 'shipping_zones', 'discounts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropColumn(['fulfilled_quantity', 'returned_quantity']);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
            $t->dropConstrainedForeignId('exchange_order_id');
            $t->dropColumn(['revision', 'payment_status', 'fulfillment_status', 'country_code', 'district', 'postal_code', 'discount_code', 'discount_total', 'tax_total', 'tax_basis_points', 'cancelled_at', 'archived_at']);
        });
    }
};
