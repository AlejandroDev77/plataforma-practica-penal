<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExpedienteArchivosRequest;
use App\Http\Resources\Api\V1\ArchivoExpedienteResource;
use App\Http\Resources\Api\V1\PaginaExpedienteResource;
use App\Jobs\ProcesarArchivoExpediente;
use App\Models\Expediente;
use App\Services\ExpedienteArchivoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExpedienteArchivoController extends Controller
{
    public function store(StoreExpedienteArchivosRequest $request, int $expediente, ExpedienteArchivoService $service): JsonResponse
    {
        $record = $this->ownedExpediente($request, $expediente);
        $files = $service->store($record, $request->file('archivos', []));
        foreach ($files as $file) {
            ProcesarArchivoExpediente::dispatch($file->getKey())->afterCommit();
        }

        return ArchivoExpedienteResource::collection(collect($files))->response()->setStatusCode(201);
    }

    public function pages(Request $request, int $expediente, int $archivo): AnonymousResourceCollection
    {
        $file = $this->ownedExpediente($request, $expediente)
            ->archivos()
            ->whereKey($archivo)
            ->firstOrFail();

        return PaginaExpedienteResource::collection(
            $file->paginas()->paginate(20)->withQueryString(),
        );
    }

    public function download(Request $request, int $expediente, int $archivo): StreamedResponse
    {
        $file = $this->ownedExpediente($request, $expediente)
            ->archivos()
            ->whereKey($archivo)
            ->firstOrFail();

        abort_unless(Storage::disk($file->disco)->exists($file->ruta_almacenamiento), 404);

        return Storage::disk($file->disco)->download($file->ruta_almacenamiento, $file->nombre_original);
    }

    public function destroy(Request $request, int $expediente, int $archivo, ExpedienteArchivoService $service): Response
    {
        $record = $this->ownedExpediente($request, $expediente);
        $service->remove($record, $archivo);

        return response()->noContent();
    }

    private function ownedExpediente(Request $request, int $id): Expediente
    {
        return $request->user()->expedientes()->whereKey($id)->firstOrFail();
    }
}
