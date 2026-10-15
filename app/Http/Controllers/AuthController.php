<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $usuario = User::create([
            'name' => $datos['name'],
            'email' => strtolower($datos['email']),
            'password' => Hash::make($datos['password']),
        ]);

        return response()->json([
            'exito' => true,
            'usuario' => $usuario,
            'token' => $usuario->createToken('scrapertracker-web', ['*'], now()->addDays(7))->plainTextToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $usuario = User::where('email', strtolower($datos['email']))->first();
        if (! $usuario || ! Hash::check($datos['password'], $usuario->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas no son válidas.'],
            ]);
        }

        return response()->json([
            'exito' => true,
            'usuario' => $usuario,
            'token' => $usuario->createToken('scrapertracker-web', ['*'], now()->addDays(7))->plainTextToken,
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'exito' => true,
            'usuario' => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['exito' => true]);
    }
}
