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
        // Main products table
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('cpu'); // CPU limit (in percentage or millicores depending on daemon implementation)
            $table->unsignedInteger('memory'); // RAM in MB
            $table->unsignedInteger('disk'); // Disk in MB
            $table->integer('swap')->default(0); // Swap in MB (use -1 for unlimited like Pterodactyl convention)
            $table->unsignedInteger('credits'); // Cost in platform credits
            $table->unsignedInteger('max_per_user')->default(1); // Max servers of this product a single user can own
            $table->boolean('disabled')->default(false); // Soft-disable product from being purchased/deployed
            $table->timestamps();
        });

        // Pivot: which nests are compatible with a given product
        Schema::create('nest_product', function (Blueprint $table) {
            $table->unsignedInteger('nest_id');
            $table->unsignedBigInteger('product_id');

            $table->unique(['nest_id', 'product_id']);

            $table->foreign('nest_id')
                ->references('id')
                ->on('nests')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        // Pivot: which nodes a product can be deployed on
        Schema::create('node_product', function (Blueprint $table) {
            $table->unsignedInteger('node_id');
            $table->unsignedBigInteger('product_id');

            $table->unique(['node_id', 'product_id']);

            $table->foreign('node_id')
                ->references('id')
                ->on('nodes')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_product');
        Schema::dropIfExists('nest_product');
        Schema::dropIfExists('products');
    }
}; 