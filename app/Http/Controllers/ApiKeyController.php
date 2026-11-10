<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'exito' => true,
            'api_key' => $request->user()->asegurarApiKey(),
        ]);
    }

    public function regenerate(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $apiKey = User::generarApiKey();
        $usuario->forceFill(['api_key' => $apiKey])->save();

        return response()->json([
            'exito' => true,
            'api_key' => $apiKey,
        ]);
    }
}
