<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = [
        'enlace',
        'user_id',
        'titulo',
        'precio_oferta',
        'precio_regular',
        'cupon',
        'disponibilidad',
        'costo_envio',
        'envio_desde',
        'descripcion',
        'frecuencia_scraping',
        'imagenes',
        'selectores',
        'monitoreo_activo',
        'proxima_ejecucion_at',
    ];

    protected function casts(): array
    {
        return [
            'imagenes' => 'array',
            'selectores' => 'array',
            'monitoreo_activo' => 'boolean',
            'proxima_ejecucion_at' => 'datetime',
        ];
    }

    public function historialPrecios()
    {
        return $this->hasMany(HistorialPrecio::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
