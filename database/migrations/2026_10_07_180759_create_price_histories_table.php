<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade'); // Relación con el producto
            $table->decimal('price', 10, 2); // Precio registrado en ese momento
            $table->timestamps(); // Fecha y hora exacta del registro para la gráfica
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_histories');
    }
};
