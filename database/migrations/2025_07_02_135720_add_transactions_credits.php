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
        //
        Schema::create('transactions_credits', function (Blueprint $table) {
            $table->id();
            // Use unsignedInteger to match the `users.id` column (created with `increments`)
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->integer('amount');
            $table->enum('type', ['debit', 'credit']);
            $table->string('description')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('transaction_id')->nullable()->unique(); // Make unique for external references
            $table->string('reference_type')->nullable(); // e.g., 'manual', 'purchase', 'refund', 'system'
            $table->string('reference_id')->nullable(); // Reference to related record (order_id, etc.)
            $table->integer('balance_before')->nullable(); // User's balance before this transaction
            $table->integer('balance_after')->nullable(); // User's balance after this transaction
            $table->json('metadata')->nullable(); // Store additional transaction data
            $table->timestamp('processed_at')->nullable(); // When transaction was actually processed
            $table->timestamps();

            // Indexes for better performance
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('reference_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('transactions_credits');
    }
};
