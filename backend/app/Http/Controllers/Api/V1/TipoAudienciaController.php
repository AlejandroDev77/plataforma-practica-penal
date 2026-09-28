<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TipoAudiencia;
use Illuminate\Http\JsonResponse;

final class TipoAudienciaController extends Controller
{
    public function index(): JsonResponse
    {
        $tipos = TipoAudiencia::query()
            ->where('activo', true)
            ->with(['etapas' => fn ($query) => $query->where('activo', true)])
            ->orderBy('orden')
            ->get()
            ->map(fn (TipoAudiencia $tipo): array => [
                'id' => $tipo->getKey(),
                'code' => $tipo->codigo,
                'name' => $tipo->nombre,
                'description' => $tipo->descripcion,
                'stages' => $tipo->etapas->map(fn ($etapa): array => [
                    'id' => $etapa->getKey(),
                    'code' => $etapa->codigo,
                    'name' => $etapa->nombre,
                    'order' => $etapa->orden,
                    'is_initial' => $etapa->es_inicial,
                    'is_final' => $etapa->es_final,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $tipos]);
    }
}
