<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_product_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('uptime_seconds')->default(0)->after('credits_charged');
        });
    }

    public function down(): void
    {
        Schema::table('user_product_purchases', function (Blueprint $table) {
            $table->dropColumn('uptime_seconds');
        });
    }
}; 