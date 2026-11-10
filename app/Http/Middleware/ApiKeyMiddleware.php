<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');
        if (! $apiKey) {
            $authorization = $request->header('Authorization', '');
            if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
                $apiKey = trim($matches[1]);
            }
        }

        if (! is_string($apiKey) || $apiKey === '') {
            return $this->unauthorized('Debes enviar una API Key válida.');
        }

        $usuario = User::where('api_key', $apiKey)->first();
        if (! $usuario) {
            return $this->unauthorized('La API Key no es válida.');
        }

        $request->setUserResolver(static fn () => $usuario);

        return $next($request);
    }

    private function unauthorized(string $mensaje): JsonResponse
    {
        return response()->json([
            'exito' => false,
            'mensaje' => $mensaje,
        ], 401);
    }
}
