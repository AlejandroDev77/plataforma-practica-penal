<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExpedienteRequest;
use App\Http\Requests\Api\V1\UpdateExpedienteRequest;
use App\Http\Resources\Api\V1\ExpedienteResource;
use App\Models\Expediente;
use App\Services\ExpedienteArchivoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ExpedienteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $search = trim($filters['search'] ?? '');

        $expedientes = $request->user()->expedientes()
            ->withCount('archivos')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('titulo', 'ilike', '%'.$search.'%')
                        ->orWhere('numero_caso', 'ilike', '%'.$search.'%');
                });
            })
            ->orderByDesc('fecha_actualizacion')
            ->orderByDesc('id_expediente')
            ->paginate((int) ($filters['per_page'] ?? 10));

        return ExpedienteResource::collection($expedientes)->response();
    }

    public function store(StoreExpedienteRequest $request): JsonResponse
    {
        $expediente = $request->user()->expedientes()->create($request->safe()->only([
            'titulo', 'descripcion', 'numero_caso',
        ]));

        return ExpedienteResource::make($expediente->refresh()->loadCount('archivos'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $expediente): JsonResponse
    {
        $record = $this->ownedExpediente($request, $expediente)
            ->loadCount('archivos')
            ->load('archivos');

        return ExpedienteResource::make($record)->response();
    }

    public function update(UpdateExpedienteRequest $request, int $expediente): JsonResponse
    {
        $record = $this->ownedExpediente($request, $expediente);
        $record->fill($request->safe()->only(['titulo', 'descripcion', 'numero_caso']))->save();

        return ExpedienteResource::make($record->loadCount('archivos'))
            ->response();
    }

    public function destroy(Request $request, int $expediente, ExpedienteArchivoService $archivos): Response
    {
        $record = $this->ownedExpediente($request, $expediente);
        $objects = $record->archivos()->get(['disco', 'ruta_almacenamiento'])
            ->map(fn ($archivo): array => ['disco' => $archivo->disco, 'ruta' => $archivo->ruta_almacenamiento])
            ->all();

        DB::transaction(fn () => $record->delete());
        $archivos->cleanPendingObjects($objects);

        return response()->noContent();
    }

    private function ownedExpediente(Request $request, int $id): Expediente
    {
        return $request->user()->expedientes()->whereKey($id)->firstOrFail();
    }
}
