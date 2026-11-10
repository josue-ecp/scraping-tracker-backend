<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiProductoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $productos = Producto::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'exito' => true,
            'datos' => $productos,
            'total' => $productos->count(),
        ]);
    }
}
