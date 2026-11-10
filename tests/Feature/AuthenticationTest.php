<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_with_a_hashed_password_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Scraper User',
            'email' => 'scraper@example.com',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('exito', true)
            ->assertJsonPath('usuario.email', 'scraper@example.com')
            ->assertJsonStructure(['token', 'usuario' => ['id', 'name', 'email']]);

        $usuario = User::where('email', 'scraper@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('secure-password', $usuario->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotNull($usuario->tokens()->first()->expires_at);
        $this->assertSame(40, strlen($usuario->api_key));
    }

    public function test_a_user_can_login_and_get_their_current_user(): void
    {
        $usuario = User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('secure-password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'LOGIN@example.com',
            'password' => 'secure-password',
        ]);

        $response->assertOk()->assertJsonPath('usuario.id', $usuario->id);
        $token = $response->json('token');

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('usuario.email', 'login@example.com');
    }

    public function test_invalid_login_credentials_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('right-password'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $usuario = User::factory()->create();
        $token = $usuario->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('exito', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_product_and_scraping_endpoints_require_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/productos')->assertUnauthorized();
        $this->postJson('/api/productos', [])->assertUnauthorized();
        $this->postJson('/api/productos/extraer', [])->assertUnauthorized();
        $this->postJson('/api/productos/inspeccionar', [])->assertUnauthorized();
    }

    public function test_new_products_are_owned_by_the_authenticated_user(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->postJson('/api/productos', [
            'enlace' => 'https://shop.example/product',
            'titulo' => 'Producto privado',
            'frecuenciaScraping' => '5',
            'selectores' => [
                'titulo' => ['selector' => 'h1'],
                'precioOferta' => ['selector' => '.price'],
            ],
        ])->assertCreated();

        $this->assertSame($usuario->id, Producto::firstOrFail()->user_id);
    }

    public function test_api_key_endpoint_returns_and_regenerates_the_user_key(): void
    {
        $usuario = User::factory()->create();
        $token = $usuario->createToken('test-token')->plainTextToken;
        $apiKeyOriginal = $usuario->api_key;

        $this->withToken($token)
            ->getJson('/api/api-key')
            ->assertOk()
            ->assertJsonPath('api_key', $apiKeyOriginal);

        $response = $this->withToken($token)
            ->postJson('/api/api-key/regenerate')
            ->assertOk();

        $apiKeyNueva = $response->json('api_key');
        $this->assertNotSame($apiKeyOriginal, $apiKeyNueva);
        $this->assertSame($apiKeyNueva, $usuario->fresh()->api_key);
    }

    public function test_api_key_returns_only_the_owner_products(): void
    {
        $usuario = User::factory()->create();
        $otroUsuario = User::factory()->create();

        Producto::create([
            'user_id' => $usuario->id,
            'enlace' => 'https://shop.example/owned',
            'titulo' => 'Producto propio',
        ]);
        Producto::create([
            'user_id' => $otroUsuario->id,
            'enlace' => 'https://shop.example/other',
            'titulo' => 'Producto ajeno',
        ]);

        $this->getJson('/api/v1/mis-productos', [
            'X-API-Key' => $usuario->api_key,
        ])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('datos.0.titulo', 'Producto propio');

        $this->getJson('/api/v1/mis-productos', [
            'Authorization' => "Bearer {$usuario->api_key}",
        ])->assertOk()->assertJsonPath('total', 1);
    }

    public function test_api_key_endpoint_rejects_missing_or_invalid_keys(): void
    {
        $this->getJson('/api/v1/mis-productos')->assertUnauthorized();
        $this->getJson('/api/v1/mis-productos', [
            'Authorization' => 'Bearer invalid-key',
        ])->assertUnauthorized();
    }
}
