<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Serialization\ApiResponse;
use App\Transformers\HealthTransformer;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(ApiResponse $apiResponse, HealthTransformer $transformer): JsonResponse
    {
        return response()->json($apiResponse->item(['status' => 'ok'], $transformer));
    }
}
