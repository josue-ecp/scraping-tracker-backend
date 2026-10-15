<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048); // Enlace de cualquier tienda
            $table->string('title'); // Título del producto
            $table->decimal('current_price', 10, 2)->nullable(); // Precio en oferta
            $table->decimal('regular_price', 10, 2)->nullable(); // Precio regular
            $table->string('coupon')->nullable(); // Cupón si lo hay
            $table->string('availability')->default('online'); // online o fisica
            $table->decimal('shipping_cost', 10, 2)->default(0); // Costo de envío
            $table->string('shipping_from')->nullable(); // Se envía desde
            $table->text('description')->nullable(); // Descripción extraída
            $table->string('scraping_interval')->default('5'); // 'latest', '5', '15' minutos
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
