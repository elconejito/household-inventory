<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInventoryMovementRecorderRequest;
use App\Models\Household;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\InventoryMovementRecorderTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

class InventoryMovementRecorderController extends Controller
{
    public function index(
        IndexInventoryMovementRecorderRequest $request,
        ApiResponse $apiResponse,
        InventoryMovementRecorderTransformer $transformer,
    ): JsonResponse {
        Gate::authorize('viewAny', InventoryMovement::class);

        $household = $this->household($request->user());
        $movementRecorderIds = InventoryMovement::query()
            ->where('household_id', $household->getKey())
            ->select('recorded_by')
            ->distinct();

        $query = QueryBuilder::for(User::query()
            ->select(['users.id', 'users.name'])
            ->whereIn('users.id', $movementRecorderIds))
            ->allowedFilters()
            ->allowedSorts('name')
            ->defaultSort('name')
            ->allowedIncludes()
            ->orderBy('users.id');

        $paginator = $query
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();
        $response = $apiResponse->collection($paginator->items(), $transformer);
        $response['meta'] = [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
        $response['links'] = [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        return response()->json($response);
    }

    private function household(User $user): Household
    {
        return $user->households()->firstOrFail();
    }
}
