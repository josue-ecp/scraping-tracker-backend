<?php

namespace App\Console\Commands;

use App\Models\HistorialPrecio;
use App\Models\Producto;
use App\Services\ScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonitorearProductos extends Command
{
    protected $signature = 'scraping:monitor-products';

    protected $description = 'Consulta los productos cuyo monitoreo está programado.';

    public function handle(ScraperService $scraper): int
    {
        Producto::query()
            ->where('monitoreo_activo', true)
            ->whereNotNull('proxima_ejecucion_at')
            ->where('proxima_ejecucion_at', '<=', now())
            ->orderBy('id')
            ->chunkById(50, function ($productos) use ($scraper): void {
                foreach ($productos as $producto) {
                    $this->actualizarProducto($producto, $scraper);
                }
            });

        return self::SUCCESS;
    }

    private function actualizarProducto(Producto $producto, ScraperService $scraper): void
    {
        try {
            $html = $scraper->fetchHtml($producto->enlace)->body();
            $valores = $scraper->extractFields($html, $producto->selectores ?? []);

            if (! empty($valores['titulo'])) {
                $producto->titulo = $valores['titulo'];
            }
            if (! empty($valores['descripcion'])) {
                $producto->descripcion = $valores['descripcion'];
            }
            if (! empty($valores['imagen'])) {
                $producto->imagenes = array_values(array_unique(array_merge(
                    $producto->imagenes ?? [],
                    [$valores['imagen']],
                )));
            }
            if (isset($valores['precioOferta']) && $valores['precioOferta'] !== '') {
                $precio = $this->normalizarPrecio($valores['precioOferta']);
                if ($precio !== null) {
                    $precioAnterior = number_format((float) $producto->precio_oferta, 2, '.', '');
                    $precioActual = number_format($precio, 2, '.', '');
                    $producto->precio_oferta = $precio;

                    if ($precioAnterior !== $precioActual) {
                        HistorialPrecio::create([
                            'producto_id' => $producto->id,
                            'precio' => $precio,
                        ]);
                    }
                }
            }

            $producto->proxima_ejecucion_at = now()->addMinutes((int) $producto->frecuencia_scraping);
            $producto->save();
        } catch (Throwable $exception) {
            Log::error('Falló el monitoreo del producto.', [
                'producto_id' => $producto->id,
                'error' => $exception->getMessage(),
            ]);

            $producto->proxima_ejecucion_at = now()->addMinutes((int) $producto->frecuencia_scraping);
            $producto->save();
        }
    }

    private function normalizarPrecio(string $value): ?float
    {
        $price = preg_replace('/[^\d.,-]/u', '', $value);
        if ($price === null || $price === '') {
            return null;
        }

        if (str_contains($price, ',') && str_contains($price, '.')) {
            $price = strrpos($price, '.') > strrpos($price, ',')
                ? str_replace(',', '', $price)
                : str_replace(',', '.', str_replace('.', '', $price));
        } elseif (str_contains($price, ',')) {
            $parts = explode(',', $price);
            $price = count($parts) === 2 && strlen($parts[1]) <= 2
                ? str_replace(',', '.', $price)
                : str_replace(',', '', $price);
        }

        return is_numeric($price) ? (float) $price : null;
    }
}
