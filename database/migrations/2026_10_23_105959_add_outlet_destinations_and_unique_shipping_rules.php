<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('country_code', 2)->default('BD');
            $table->string('district')->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX shipping_zones_destination_unique ON shipping_zones (country_code, COALESCE(district, ''))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX shipping_zones_destination_unique');
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['country_code', 'district']);
        });
    }
};
