<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->onDelete('cascade'); // Relación en español
            $table->decimal('precio', 10, 2); // Precio registrado para la gráfica tipo Keepa
            $table->timestamps(); // Fecha y hora automática
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_precios');
    }
};
