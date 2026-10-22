<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 40)->unique();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
        });
        DB::table('orders')->orderBy('id')->chunkById(200, function ($orders) {
            foreach ($orders as $order) {
                $phone = preg_replace('/[^0-9]/', '', $order->phone);
                if (str_starts_with($phone, '00')) {
                    $phone = substr($phone, 2);
                }
                if (preg_match('/^01[0-9]{9}$/', $phone)) {
                    $phone = '88'.$phone;
                }
                if ($phone === '') {
                    continue;
                }
                DB::table('customers')->insertOrIgnore([
                    'name' => $order->customer_name, 'phone' => $phone,
                    'email' => $order->email, 'address' => $order->address,
                    'created_at' => $order->created_at, 'updated_at' => $order->updated_at,
                ]);
                DB::table('orders')->where('id', $order->id)->update([
                    'customer_id' => DB::table('customers')->where('phone', $phone)->value('id'),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('customers');
    }
};
