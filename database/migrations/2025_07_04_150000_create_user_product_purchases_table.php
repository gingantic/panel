<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_product_purchases', function (Blueprint $table) {
            $table->id();

            // Ownership & relationships
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('egg_id')->nullable();
            $table->unsignedInteger('server_id')->nullable(); // Server provisioned for this purchase

            // Financials & billing
            $table->integer('credits_charged'); // Credits taken at creation (one cycle upfront)
            $table->enum('billing_cycle', ['hourly', 'daily', 'weekly', 'monthly', 'yearly'])->default('monthly');
            $table->timestamp('next_renew_at')->nullable(); // When the next charge should occur

            // Lifecycle
            $table->enum('status', ['creating', 'active', 'suspended', 'cancelled'])->default('creating');

            // Link to the transaction that performed the charge (if stored)
            $table->unsignedBigInteger('transaction_id')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('egg_id')->references('id')->on('eggs')->nullOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
            $table->foreign('transaction_id')->references('id')->on('transactions_credits')->nullOnDelete();

            // Helpful indexes
            $table->index(['user_id', 'status']);
            $table->index('next_renew_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_product_purchases');
    }
}; 