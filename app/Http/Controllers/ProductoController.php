<?php

namespace App\Http\Controllers;

use App\Models\HistorialPrecio;
use App\Models\Producto;
use App\Services\ScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductoController extends Controller
{
    // ============================================================
    // LISTAR PRODUCTOS
    // ============================================================

    public function index(Request $request)
    {
        return response()->json([
            'exito' => true,
            'datos' => Producto::with('historialPrecios')
                ->where('user_id', $request->user()->id)
                ->latest()
                ->get(),
        ]);
    }

    // ============================================================
    // GUARDAR PRODUCTO
    // ============================================================

    public function store(Request $request)
    {
        // Limpiamos cadenas vacías a null para evitar fallos de validación numérica
        $input = $request->all();
        foreach (['precioOferta', 'precioRegular', 'costoEnvio'] as $campo) {
            if (isset($input[$campo]) && $input[$campo] === '') {
                $input[$campo] = null;
            }
        }
        $request->merge($input);

        $datosValidados = $request->validate([
            'enlace' => 'required|url',
            'titulo' => 'required|string|max:255',
            'precioOferta' => 'nullable|numeric',
            'precioRegular' => 'nullable|numeric',
            'cupon' => 'nullable|string',
            'disponibilidad' => 'nullable|string',
            'costoEnvio' => 'nullable|numeric',
            'envioDesde' => 'nullable|string',
            'descripcion' => 'nullable|string',
            'frecuenciaScraping' => 'required|in:5,15,30',
            'imagen' => 'nullable|string',
            'imagenes' => 'nullable|array',
            'imagenes.*' => 'url:http,https',
            'selectores' => 'required|array',
            'selectores.titulo.selector' => 'required|string|max:256',
            'selectores.precioOferta.selector' => 'required|string|max:256',
        ]);

        $producto = DB::transaction(function () use ($datosValidados) {
            $producto = Producto::create([
                'enlace' => $datosValidados['enlace'],
                'user_id' => auth()->id(),
                'titulo' => $datosValidados['titulo'],
                'precio_oferta' => $datosValidados['precioOferta'] ?? null,
                'precio_regular' => $datosValidados['precioRegular'] ?? null,
                'cupon' => $datosValidados['cupon'] ?? null,
                'disponibilidad' => $datosValidados['disponibilidad'] ?? 'online',
                'costo_envio' => $datosValidados['costoEnvio'] ?? 0,
                'envio_desde' => $datosValidados['envioDesde'] ?? null,
                'descripcion' => $datosValidados['descripcion'] ?? null,
                'frecuencia_scraping' => $datosValidados['frecuenciaScraping'],
                'imagenes' => $datosValidados['imagenes'] ?? [],
                'selectores' => $datosValidados['selectores'] ?? [],
                'monitoreo_activo' => true,
                'proxima_ejecucion_at' => now()->addMinutes((int) $datosValidados['frecuenciaScraping']),
            ]);

            if (isset($datosValidados['precioOferta']) && $datosValidados['precioOferta'] !== '') {
                HistorialPrecio::create([
                    'producto_id' => $producto->id,
                    'precio' => $datosValidados['precioOferta'],
                ]);
            }

            return $producto;
        });

        return response()->json([
            'exito' => true,
            'mensaje' => '¡Producto guardado y programado con éxito!',
            'datos' => $producto,
        ], 201);
    }

    public function actualizarFrecuencia(Request $request, int $producto): JsonResponse
    {
        $datos = $request->validate([
            'frecuencia' => ['required', 'integer', 'in:5,15,30'],
        ]);

        $producto = Producto::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($producto);

        $producto->frecuencia_scraping = (string) $datos['frecuencia'];
        $producto->proxima_ejecucion_at = now()->addMinutes($datos['frecuencia']);
        $producto->save();

        return response()->json([
            'exito' => true,
            'mensaje' => 'Frecuencia de monitoreo actualizada.',
            'datos' => $producto,
        ]);
    }

    // ============================================================
    // EXTRAER DATOS DE CUALQUIER TIENDA
    // ============================================================

    public function extraerDatos(Request $request, ScraperService $scraper)
    {
        $request->validate([
            'enlace' => 'required|url',
        ]);

        $enlace = $request->input('enlace');

        try {
            $respuesta = $scraper->fetchHtml($enlace);
            $html = $respuesta->body();

            if (empty($html)) {
                return response()->json([
                    'exito' => false,
                    'mensaje' => 'La página no devolvió contenido.',
                    'datos' => null,
                ], 422);
            }

            // Datos que intentaremos obtener
            $datos = [
                'enlace' => $enlace,
                'titulo' => null,
                'precioOferta' => null,
                'precioRegular' => null,
                'cupon' => null,
                'disponibilidad' => null,
                'costoEnvio' => null,
                'envioDesde' => null,
                'descripcion' => null,
                'imagen' => null,
                'imagenes' => [],
                'moneda' => null,
                'sitio' => parse_url($enlace, PHP_URL_HOST),
            ];

            // ========================================================
            // 0. EXTRACCIÓN ESPECÍFICA PARA AMAZON (PRECIO PRINCIPAL)
            // ========================================================
            if (str_contains($enlace, 'amazon.com') && preg_match('/class="a-price-whole"[^>]*>([0-9,]+)/i', $html, $matchAmazonPrice)) {
                if (! empty($matchAmazonPrice[1])) {
                    $datos['precioOferta'] = $this->limpiarPrecio($matchAmazonPrice[1]);
                }
            }

            // ========================================================
            // 1. JSON-LD / SCHEMA.ORG
            // ========================================================

            $productosJsonLd = [];

            preg_match_all(
                '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
                $html,
                $bloquesJsonLd
            );

            foreach ($bloquesJsonLd[1] ?? [] as $bloque) {
                $bloque = html_entity_decode($bloque);

                $json = json_decode(trim($bloque), true);

                if (
                    json_last_error() !== JSON_ERROR_NONE ||
                    ! is_array($json)
                ) {
                    continue;
                }

                // Producto directo
                if (
                    isset($json['@type']) ||
                    isset($json['name']) ||
                    isset($json['offers'])
                ) {
                    $productosJsonLd[] = $json;
                }

                // Producto dentro de @graph
                if (
                    isset($json['@graph']) &&
                    is_array($json['@graph'])
                ) {
                    foreach ($json['@graph'] as $elemento) {
                        if (is_array($elemento)) {
                            $productosJsonLd[] = $elemento;
                        }
                    }
                }
            }

            foreach ($productosJsonLd as $producto) {
                $tipo = $producto['@type'] ?? '';

                if (is_array($tipo)) {
                    $tipo = implode(',', $tipo);
                }

                // Título
                if (
                    empty($datos['titulo']) &&
                    ! empty($producto['name']) &&
                    (
                        stripos($tipo, 'Product') !== false ||
                        isset($producto['offers'])
                    )
                ) {
                    $datos['titulo'] = $this->limpiarTexto(
                        $producto['name']
                    );
                }

                // Descripción
                if (
                    empty($datos['descripcion']) &&
                    ! empty($producto['description'])
                ) {
                    $datos['descripcion'] = $this->limpiarTexto(
                        $producto['description']
                    );
                }

                // Imagen principal e imágenes adicionales
                if (! empty($producto['image'])) {
                    if (is_array($producto['image'])) {
                        foreach ($producto['image'] as $imgItem) {
                            if (is_string($imgItem)) {
                                $datos['imagenes'][] = $imgItem;
                            }
                        }
                    } elseif (is_string($producto['image'])) {
                        $datos['imagenes'][] = $producto['image'];
                    }
                }

                // Ofertas
                if (isset($producto['offers'])) {
                    $ofertas = $producto['offers'];

                    if (
                        is_array($ofertas) &&
                        isset($ofertas[0])
                    ) {
                        $oferta = $ofertas[0];
                    } else {
                        $oferta = $ofertas;
                    }

                    if (is_array($oferta)) {
                        // Precio (solo si no se detectó antes por Amazon)
                        if (
                            empty($datos['precioOferta']) &&
                            isset($oferta['price'])
                        ) {
                            $datos['precioOferta'] = $this->limpiarPrecio(
                                $oferta['price']
                            );
                        }

                        // Moneda
                        if (
                            empty($datos['moneda']) &&
                            ! empty($oferta['priceCurrency'])
                        ) {
                            $datos['moneda'] = $oferta['priceCurrency'];
                        }

                        // Disponibilidad
                        if (
                            empty($datos['disponibilidad']) &&
                            ! empty($oferta['availability'])
                        ) {
                            $datos['disponibilidad'] =
                                $this->normalizarDisponibilidad(
                                    $oferta['availability']
                                );
                        }
                    }
                }
            }

            // ========================================================
            // 2. OPEN GRAPH / META TAGS
            // ========================================================

            $metas = [];

            // property/name antes de content
            preg_match_all(
                '/<meta\s+[^>]*(?:property|name)\s*=\s*["\']([^"\']+)["\'][^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*>/is',
                $html,
                $coincidencias
            );

            foreach (
                $coincidencias[1] ?? [] as $indice => $nombre
            ) {
                $valor = $coincidencias[2][$indice] ?? '';

                $metas[strtolower(trim($nombre))] =
                    html_entity_decode(trim($valor));
            }

            // content antes de property/name
            preg_match_all(
                '/<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*(?:property|name)\s*=\s*["\']([^"\']+)["\'][^>]*>/is',
                $html,
                $coincidenciasInvertidas
            );

            foreach (
                $coincidenciasInvertidas[2] ?? [] as $indice => $nombre
            ) {
                $valor = $coincidenciasInvertidas[1][$indice] ?? '';

                $metas[strtolower(trim($nombre))] =
                    html_entity_decode(trim($valor));
            }

            // Título
            if (empty($datos['titulo'])) {
                $datos['titulo'] =
                    $metas['og:title'] ??
                    $metas['twitter:title'] ??
                    null;
            }

            // Descripción
            if (empty($datos['descripcion'])) {
                $datos['descripcion'] =
                    $metas['og:description'] ??
                    $metas['description'] ??
                    $metas['twitter:description'] ??
                    null;
            }

            // Imágenes Open Graph y Twitter
            if (! empty($metas['og:image'])) {
                $datos['imagenes'][] = $metas['og:image'];
            }
            if (! empty($metas['twitter:image'])) {
                $datos['imagenes'][] = $metas['twitter:image'];
            }

            // Precio
            if (empty($datos['precioOferta'])) {
                $precioMeta =
                    $metas['product:price:amount'] ??
                    $metas['og:price:amount'] ??
                    $metas['price'] ??
                    null;

                if ($precioMeta !== null) {
                    $datos['precioOferta'] =
                        $this->limpiarPrecio($precioMeta);
                }
            }

            // Moneda
            if (empty($datos['moneda'])) {
                $datos['moneda'] =
                    $metas['product:price:currency'] ??
                    $metas['og:price:currency'] ??
                    null;
            }

            // ========================================================
            // 3. ETIQUETA <TITLE>
            // ========================================================

            if (empty($datos['titulo'])) {
                preg_match(
                    '/<title[^>]*>(.*?)<\/title>/is',
                    $html,
                    $tituloHtml
                );

                if (! empty($tituloHtml[1])) {
                    $datos['titulo'] = $this->limpiarTexto(
                        $tituloHtml[1]
                    );
                }
            }

            // ========================================================
            // 4. BUSCAR PRECIO DE OFERTA Y REGULAR EN HTML (UNIVERSAL)
            // ========================================================

            if (empty($datos['precioOferta'])) {
                $patronesPrecio = [
                    '/(?:price|precio|sale_price|salePrice)[^0-9]{0,80}([0-9]{1,6}(?:[.,][0-9]{1,2})?)/i',
                    '/[$€£]\s*([0-9]{1,6}(?:[.,][0-9]{1,2})?)/i',
                    '/([0-9]{1,6}(?:[.,][0-9]{1,2})?)\s*(?:MXN|USD|EUR|€|£)/i',
                ];

                foreach ($patronesPrecio as $patron) {
                    if (
                        preg_match(
                            $patron,
                            $html,
                            $precioEncontrado
                        )
                    ) {
                        if (! empty($precioEncontrado[1])) {
                            $datos['precioOferta'] =
                                $this->limpiarPrecio(
                                    $precioEncontrado[1]
                                );

                            break;
                        }
                    }
                }
            }

            // Búsqueda universal de precio regular / de lista
            if (empty($datos['precioRegular'])) {
                $patronesRegular = [
                    '/(?:precio de lista|precio regular|list-price|price-regular|basisPrice|previous-price)[^0-9]{0,60}([0-9]{1,6}(?:[.,][0-9]{1,2})?)/ui',
                    '/class="[^"]*(?:strike|list-price|a-text-price)[^"]*"[^>]*>.*?([0-9]{1,6}(?:[.,][0-9]{1,2})?)/is',
                ];

                foreach ($patronesRegular as $patronReg) {
                    if (preg_match($patronReg, $html, $matchReg)) {
                        if (! empty($matchReg[1])) {
                            $precioRegLimpio = $this->limpiarPrecio($matchReg[1]);
                            if ($precioRegLimpio && $precioRegLimpio > ($datos['precioOferta'] ?? 0)) {
                                $datos['precioRegular'] = $precioRegLimpio;
                                break;
                            }
                        }
                    }
                }
            }

            // Recolectar imágenes adicionales del HTML mediante etiquetas <img>
            preg_match_all('/<img[^>]+src=["\'](https?:\/\/[^"\']+\.(?:jpg|jpeg|png|webp))["\'][^>]*>/i', $html, $matchImgs);
            if (! empty($matchImgs[1])) {
                foreach ($matchImgs[1] as $imgUrl) {
                    if (! str_contains($imgUrl, 'icon') && ! str_contains($imgUrl, 'logo') && count($datos['imagenes']) < 8) {
                        $datos['imagenes'][] = $imgUrl;
                    }
                }
            }

            // Limpieza y unicidad de la galería de imágenes
            $datos['imagenes'] = array_values(array_unique($datos['imagenes']));
            $datos['imagen'] = $datos['imagenes'][0] ?? null;

            // ========================================================
            // 5. DISPONIBILIDAD
            // ========================================================

            if (empty($datos['disponibilidad'])) {
                $textoHtml = Str::lower(
                    strip_tags($html)
                );

                if (
                    Str::contains(
                        $textoHtml,
                        [
                            'in stock',
                            'en stock',
                            'disponible',
                        ]
                    )
                ) {
                    $datos['disponibilidad'] = 'Disponible';
                } elseif (
                    Str::contains(
                        $textoHtml,
                        [
                            'out of stock',
                            'agotado',
                            'no disponible',
                        ]
                    )
                ) {
                    $datos['disponibilidad'] = 'Agotado';
                }
            }

            // ========================================================
            // LIMPIEZA FINAL
            // ========================================================

            foreach ($datos as $campo => $valor) {
                if (is_string($valor)) {
                    $datos[$campo] = trim($valor);
                }
            }

            // ========================================================
            // COMPROBAR SI ENCONTRAMOS INFORMACIÓN
            // ========================================================

            $selectores = $scraper->detectedSelectors($html);
            $valoresDetectados = $scraper->extractFields($html, $selectores);
            $datos['titulo'] = ($valoresDetectados['titulo'] ?? '') ?: $datos['titulo'];
            if ($datos['precioOferta'] === null || $datos['precioOferta'] === '') {
                $datos['precioOferta'] = $valoresDetectados['precioOferta'] ?? '';
            }
            $datos['descripcion'] = $datos['descripcion'] ?: ($valoresDetectados['descripcion'] ?? '');
            if (empty($datos['imagenes']) && ! empty($valoresDetectados['imagen'])) {
                $datos['imagenes'][] = $valoresDetectados['imagen'];
                $datos['imagen'] = $valoresDetectados['imagen'];
            }
            $camposEncontrados = array_filter([
                $datos['titulo'],
                $datos['precioOferta'],
                $datos['descripcion'],
                $datos['imagen'],
                $datos['disponibilidad'],
            ], static fn ($value) => $value !== null && $value !== '');

            // ========================================================
            // RESPUESTA
            // ========================================================

            return response()->json([
                'exito' => true,
                'mensaje' => empty($camposEncontrados)
                    ? 'HTML descargado. No se detectaron campos automáticamente; puedes definirlos con selectores CSS.'
                    : 'Datos extraídos correctamente.',
                'datos' => $datos,
                'selectores' => $selectores,
                'html' => $html,
                'metadatos' => $scraper->metadata($html, $enlace),
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'exito' => false,
                'mensaje' => $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Ocurrió un error al intentar analizar la página.',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
                'datos' => null,
            ], $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException ? 422 : 500);
        }
    }

    public function inspeccionarSelectores(Request $request, ScraperService $scraper)
    {
        $datos = $request->validate([
            'enlace' => 'required|url',
            'selectores' => 'required|array',
            'selectores.*.etiqueta' => 'nullable|string|max:100',
            'selectores.*.selector' => 'required|string|max:256',
            'selectores.*.atributo' => 'nullable|string|max:64',
        ]);

        try {
            $html = $scraper->fetchHtml($datos['enlace'])->body();
            $valores = $scraper->extractFields($html, $datos['selectores']);

            foreach ($datos['selectores'] as $campo => $selector) {
                $datos['selectores'][$campo]['valor'] = $valores[$campo] ?? '';
            }

            return response()->json([
                'exito' => true,
                'selectores' => $datos['selectores'],
                'valores' => $valores,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'exito' => false,
                'mensaje' => $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : (config('app.debug') ? $e->getMessage() : 'No fue posible probar los selectores.'),
            ], 422);
        }
    }

    // ============================================================
    // LIMPIAR TEXTO
    // ============================================================

    private function limpiarTexto($texto)
    {
        if (! is_string($texto)) {
            return null;
        }

        $texto = strip_tags($texto);

        $texto = html_entity_decode(
            $texto,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $texto = preg_replace('/\s+/', ' ', $texto);

        return trim($texto);
    }

    // ============================================================
    // LIMPIAR PRECIO
    // ============================================================

    private function limpiarPrecio($precio)
    {
        if ($precio === null || $precio === '') {
            return null;
        }

        $precio = strip_tags((string) $precio);
        $precio = trim($precio);

        $precio = preg_replace(
            '/[^\d.,-]/u',
            '',
            $precio
        );

        if (
            str_contains($precio, ',') &&
            str_contains($precio, '.')
        ) {
            if (
                strrpos($precio, '.') >
                strrpos($precio, ',')
            ) {
                $precio = str_replace(',', '', $precio);
            } else {
                $precio = str_replace('.', '', $precio);
                $precio = str_replace(',', '.', $precio);
            }
        } elseif (str_contains($precio, ',')) {
            $partes = explode(',', $precio);

            if (
                count($partes) === 2 &&
                strlen($partes[1]) <= 2
            ) {
                $precio = str_replace(',', '.', $precio);
            } else {
                $precio = str_replace(',', '', $precio);
            }
        }

        return is_numeric($precio)
            ? (float) $precio
            : null;
    }

    // ============================================================
    // NORMALIZAR DISPONIBILIDAD
    // ============================================================

    private function normalizarDisponibilidad($valor)
    {
        $valor = strtolower((string) $valor);

        if (str_contains($valor, 'instock')) {
            return 'Disponible';
        }

        if (
            str_contains($valor, 'outofstock') ||
            str_contains($valor, 'soldout')
        ) {
            return 'Agotado';
        }

        if (str_contains($valor, 'preorder')) {
            return 'Preventa';
        }

        return $valor;
    }
}
