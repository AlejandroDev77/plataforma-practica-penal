<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class SystemStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'service' => 'jurissim-api',
                'status' => 'available',
                'version' => 'v1',
            ],
        ]);
    }
}
