<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->json('selectores')->nullable();
            $table->json('imagenes')->nullable();
            $table->boolean('monitoreo_activo')->default(true);
            $table->timestamp('proxima_ejecucion_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropIndex(['proxima_ejecucion_at']);
            $table->dropColumn([
                'selectores',
                'imagenes',
                'monitoreo_activo',
                'proxima_ejecucion_at',
            ]);
        });
    }
};
