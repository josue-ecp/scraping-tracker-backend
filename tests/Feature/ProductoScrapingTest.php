<?php

namespace Tests\Feature;

use App\Models\HistorialPrecio;
use App\Models\Producto;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductoScrapingTest extends TestCase
{
    use RefreshDatabase;

    protected User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = User::factory()->create();
        $this->actingAs($this->usuario);
    }

    public function test_it_returns_html_metadata_and_detected_selectors(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('', 302, ['Location' => '/final'])
                ->push(
                    '<html><head><title>Demo</title><meta property="og:image" content="https://shop.example/image.jpg"></head><body><h1 class="product-title">Producto demo</h1><span class="price">$12.50</span></body></html>',
                    200,
                    ['Content-Type' => 'text/html; charset=UTF-8'],
                ),
        ]);

        $this->postJson('/api/productos/extraer', [
            'enlace' => 'http://1.1.1.1/producto',
        ])->assertOk()
            ->assertJsonPath('exito', true)
            ->assertJsonPath('datos.titulo', 'Producto demo')
            ->assertJsonPath('selectores.titulo.selector', 'h1')
            ->assertJsonPath('selectores.titulo.valor', 'Producto demo')
            ->assertJsonPath('metadatos.tituloDocumento', 'Demo')
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'product-title'))
            ->assertJsonPath('htmlPreview', fn ($html) => str_contains($html, 'product-title')
                && ! str_contains($html, 'https://shop.example/image.jpg'));

        Http::assertSentCount(2);
    }

    public function test_it_stores_selector_configuration_and_the_first_price_snapshot(): void
    {
        $response = $this->postJson('/api/productos', [
            'enlace' => 'https://1.1.1.1/producto',
            'titulo' => 'Producto demo',
            'precioOferta' => '19.99',
            'frecuenciaScraping' => '15',
            'imagenes' => ['https://shop.example/image.jpg'],
            'selectores' => [
                'titulo' => ['etiqueta' => 'Nombre', 'selector' => 'h1', 'atributo' => null],
                'precioOferta' => ['etiqueta' => 'Precio', 'selector' => '.price', 'atributo' => null],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('exito', true);

        $producto = Producto::firstOrFail();
        $this->assertSame($this->usuario->id, $producto->user_id);
        $this->assertSame('15', $producto->frecuencia_scraping);
        $this->assertSame('.price', $producto->selectores['precioOferta']['selector']);
        $this->assertSame(['https://shop.example/image.jpg'], $producto->imagenes);
        $this->assertTrue($producto->monitoreo_activo);
        $this->assertNotNull($producto->proxima_ejecucion_at);
        $this->assertDatabaseHas('historial_precios', [
            'producto_id' => $producto->id,
            'precio' => '19.99',
        ]);
    }

    public function test_it_previews_user_defined_css_selectors(): void
    {
        Http::fake([
            '*' => Http::response(
                '<main><h1 class="custom-title">Título personalizado</h1><span class="current-price">$30.00</span></main>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->postJson('/api/productos/inspeccionar', [
            'enlace' => 'https://1.1.1.1/producto',
            'selectores' => [
                'titulo' => ['etiqueta' => 'Nombre', 'selector' => '.custom-title', 'atributo' => ''],
                'precioOferta' => ['etiqueta' => 'Precio', 'selector' => '.current-price', 'atributo' => null],
            ],
        ])->assertOk()
            ->assertJsonPath('selectores.titulo.valor', 'Título personalizado')
            ->assertJsonPath('valores.precioOferta', '$30.00');
    }

    public function test_it_sanitizes_the_preview_html(): void
    {
        Http::fake([
            '*' => Http::response(
                '<html><head><script>alert(1)</script><style>@import "../css/theme.css";body{background-image:url("../images/bg.png")}</style><link rel="stylesheet" href="/assets/site.css"></head><body><img src="../images/product.jpg" srcset="../images/small.jpg 1x, /images/large.jpg 2x" onerror="alert(1)"><h1 onclick="alert(1)">Producto</h1><img src="javascript:alert(1)"></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $response = $this->postJson('/api/productos/extraer', [
            'enlace' => 'https://1.1.1.1/catalog/product',
        ])->assertOk();
        $preview = $response->json('htmlPreview');
        $this->assertStringNotContainsString('<script', $preview);
        $this->assertStringNotContainsString('onerror', $preview);
        $this->assertStringNotContainsString('onclick', $preview);
        $this->assertStringNotContainsString('javascript:', $preview);
        $this->assertStringContainsString('<style>', $preview);
        $this->assertStringContainsString('@import "https://1.1.1.1/css/theme.css"', $preview);
        $this->assertStringContainsString('url("https://1.1.1.1/images/bg.png")', $preview);
        $this->assertStringContainsString('<link rel="stylesheet" href="https://1.1.1.1/assets/site.css">', $preview);
        $this->assertStringContainsString('src="https://1.1.1.1/images/product.jpg"', $preview);
        $this->assertStringContainsString('srcset="https://1.1.1.1/images/small.jpg 1x, https://1.1.1.1/images/large.jpg 2x"', $preview);
        $this->assertStringContainsString("style-src 'unsafe-inline' http: https:", $preview);
    }

    public function test_it_extracts_a_unique_nth_of_type_css_selector(): void
    {
        Http::fake([
            '*' => Http::response(
                '<main><span>Primero</span><span>Segundo</span></main>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->postJson('/api/productos/inspeccionar', [
            'enlace' => 'https://1.1.1.1/producto',
            'selectores' => [
                'titulo' => ['etiqueta' => 'Título', 'selector' => 'main > span:nth-of-type(2)', 'atributo' => null],
            ],
        ])->assertOk()
            ->assertJsonPath('valores.titulo', 'Segundo');
    }

    public function test_it_uses_custom_css_selectors_to_record_monitoring_prices(): void
    {
        $producto = Producto::create([
            'enlace' => 'http://1.1.1.1/producto',
            'titulo' => 'Producto demo',
            'precio_oferta' => 10,
            'frecuencia_scraping' => '5',
            'selectores' => [
                'titulo' => ['selector' => 'main > h1', 'atributo' => null],
                'precioOferta' => ['selector' => '.current-price', 'atributo' => null],
            ],
            'imagenes' => [],
            'monitoreo_activo' => true,
            'proxima_ejecucion_at' => now()->subMinute(),
        ]);

        Http::fake([
            '*' => Http::response(
                '<main><h1>Nombre actualizado</h1><span class="current-price">$24.95</span></main>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->artisan('scraping:monitor-products')->assertSuccessful();

        $producto->refresh();
        $this->assertSame('Nombre actualizado', $producto->titulo);
        $this->assertSame('24.95', $producto->precio_oferta);
        $this->assertSame(1, HistorialPrecio::where('producto_id', $producto->id)->count());
        $this->assertTrue($producto->proxima_ejecucion_at->isFuture());
    }

    public function test_it_rejects_private_network_targets(): void
    {
        $this->postJson('/api/productos/extraer', [
            'enlace' => 'http://127.0.0.1/admin',
        ])->assertUnprocessable()
            ->assertJsonPath('exito', false);
    }

    public function test_it_rejects_redirects_to_private_network_targets(): void
    {
        Http::fake([
            '*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
        ]);

        $this->postJson('/api/productos/extraer', [
            'enlace' => 'http://1.1.1.1/producto',
        ])->assertUnprocessable()
            ->assertJsonPath('exito', false);

        Http::assertSentCount(1);
    }

    public function test_a_user_only_sees_their_own_products(): void
    {
        $propio = Producto::create([
            'user_id' => $this->usuario->id,
            'enlace' => 'https://shop.example/own',
            'titulo' => 'Mi producto',
            'frecuencia_scraping' => '5',
        ]);
        Producto::create([
            'user_id' => User::factory()->create()->id,
            'enlace' => 'https://shop.example/other',
            'titulo' => 'Producto de otra cuenta',
            'frecuencia_scraping' => '5',
        ]);

        $this->getJson('/api/productos')
            ->assertOk()
            ->assertJsonCount(1, 'datos')
            ->assertJsonPath('datos.0.id', $propio->id);
    }

    public function test_a_user_can_update_their_product_scraping_frequency(): void
    {
        Carbon::setTestNow('2026-10-12 12:00:00');

        $producto = Producto::create([
            'user_id' => $this->usuario->id,
            'enlace' => 'https://shop.example/product',
            'titulo' => 'Mi producto',
            'frecuencia_scraping' => '5',
            'monitoreo_activo' => true,
            'proxima_ejecucion_at' => now()->addMinutes(5),
        ]);

        $this->patchJson("/api/productos/{$producto->id}/frecuencia", [
            'frecuencia' => 15,
        ])->assertOk()
            ->assertJsonPath('exito', true)
            ->assertJsonPath('datos.frecuencia_scraping', '15')
            ->assertJsonPath('datos.proxima_ejecucion_at', '2026-10-12T12:15:00.000000Z');

        $this->assertDatabaseHas('productos', [
            'id' => $producto->id,
            'frecuencia_scraping' => '15',
            'proxima_ejecucion_at' => '2026-10-12 12:15:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_frequency_update_rejects_values_outside_the_allowed_intervals(): void
    {
        $producto = Producto::create([
            'user_id' => $this->usuario->id,
            'enlace' => 'https://shop.example/product',
            'titulo' => 'Mi producto',
            'frecuencia_scraping' => '5',
        ]);

        $this->patchJson("/api/productos/{$producto->id}/frecuencia", [
            'frecuencia' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('frecuencia');

        $this->assertSame('5', $producto->fresh()->frecuencia_scraping);
    }

    public function test_a_user_cannot_change_another_users_product_frequency(): void
    {
        $producto = Producto::create([
            'user_id' => User::factory()->create()->id,
            'enlace' => 'https://shop.example/product',
            'titulo' => 'Producto ajeno',
            'frecuencia_scraping' => '5',
        ]);

        $this->patchJson("/api/productos/{$producto->id}/frecuencia", [
            'frecuencia' => 30,
        ])->assertNotFound();
    }
}
