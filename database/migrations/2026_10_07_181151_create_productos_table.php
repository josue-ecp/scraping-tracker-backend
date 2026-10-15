<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('enlace', 2048); // URL o link del producto
            $table->string('titulo'); // Título del producto
            $table->decimal('precio_oferta', 10, 2)->nullable(); // Precio actual / oferta
            $table->decimal('precio_regular', 10, 2)->nullable(); // Precio regular / antes
            $table->string('cupon')->nullable(); // Cupón de descuento
            $table->string('disponibilidad')->default('online'); // online o fisica
            $table->decimal('costo_envio', 10, 2)->default(0); // Costo de envío
            $table->string('envio_desde')->nullable(); // Ubicación de envío
            $table->text('descripcion')->nullable(); // Descripción del producto
            $table->string('frecuencia_scraping')->default('5'); // 'latest', '5', '15' minutos
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
