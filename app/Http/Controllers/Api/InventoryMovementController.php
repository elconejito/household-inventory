<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateInventoryMovement;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInventoryMovementRequest;
use App\Http\Requests\StoreInventoryMovementRequest;
use App\Models\Household;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\InventoryMovementTransformer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\QueryBuilder;

class InventoryMovementController extends Controller
{
    public function index(IndexInventoryMovementRequest $request, ApiResponse $apiResponse, InventoryMovementTransformer $transformer): JsonResponse
    {
        Gate::authorize('viewAny', InventoryMovement::class);
        $household = $this->household($request->user());
        $includes = $this->requestedIncludes($request->query('include'));
        $query = QueryBuilder::for(InventoryMovement::query()->where('household_id', $household->getKey()))
            ->allowedFilters(
                AllowedFilter::exact('item_id'),
                AllowedFilter::exact('movement_type'),
                AllowedFilter::exact('recorded_by'),
                AllowedFilter::callback('recorded_from', fn (Builder $query, mixed $value): Builder => $query->where('recorded_at', '>=', Carbon::parse($value)->utc())),
                AllowedFilter::callback('recorded_until', fn (Builder $query, mixed $value): Builder => $query->where('recorded_at', '<=', $this->recordedUntil((string) $value))),
                AllowedFilter::callback('from_location_id', fn (Builder $query, mixed $value): Builder => $query->where('movement_type', 'transfer')->whereHas('entries', fn (Builder $entries): Builder => $entries->where('location_id', $value)->where('quantity_delta', '<', 0))),
                AllowedFilter::callback('to_location_id', fn (Builder $query, mixed $value): Builder => $query->where('movement_type', 'transfer')->whereHas('entries', fn (Builder $entries): Builder => $entries->where('location_id', $value)->where('quantity_delta', '>', 0))),
                AllowedFilter::callback('location_id', fn (Builder $query, mixed $value): Builder => $query->whereHas('entries', fn (Builder $entries): Builder => $entries->where('location_id', $value))),
            )
            ->allowedIncludes('item', 'entries.location', AllowedInclude::relationship('recorded_by', 'recorder'))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id');
        $paginator = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return response()->json($this->paginated($paginator, $apiResponse, $transformer, $includes));
    }

    public function store(StoreInventoryMovementRequest $request, CreateInventoryMovement $movements, ApiResponse $apiResponse, InventoryMovementTransformer $transformer): JsonResponse
    {
        Gate::authorize('create', InventoryMovement::class);
        $includes = $this->requestedIncludes($request->query('include'));
        $movement = $movements->create($this->household($request->user()), $request->user(), $request->validated('data'));
        if ($includes !== []) {
            $movement->load($this->relationshipIncludes($includes));
        }

        return response()->json($apiResponse->item($movement, $transformer, $includes), 201);
    }

    public function show(Request $request, string $inventory_movement, ApiResponse $apiResponse, InventoryMovementTransformer $transformer): JsonResponse
    {
        $movement = InventoryMovement::query()->where('household_id', $this->household($request->user())->getKey())->findOrFail($inventory_movement);
        Gate::authorize('view', $movement);
        $includes = $this->requestedIncludes($request->query('include'));
        if ($includes !== []) {
            $movement->load($this->relationshipIncludes($includes));
        }

        return response()->json($apiResponse->item($movement, $transformer, $includes));
    }

    private function household(User $user): Household
    {
        return $user->households()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function paginated(LengthAwarePaginator $paginator, ApiResponse $apiResponse, InventoryMovementTransformer $transformer, array $includes): array
    {
        $response = $apiResponse->collection($paginator->items(), $transformer, $includes);
        $response['meta'] = ['current_page' => $paginator->currentPage(), 'from' => $paginator->firstItem(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()];
        $response['links'] = ['first' => $paginator->url(1), 'last' => $paginator->url($paginator->lastPage()), 'prev' => $paginator->previousPageUrl(), 'next' => $paginator->nextPageUrl()];

        return $response;
    }

    /** @return array<int, string> */
    private function requestedIncludes(mixed $value): array
    {
        $allowed = ['item', 'entries', 'entries.location', 'recorded_by'];
        if ($value === null || $value === '') {
            return [];
        }
        if (! is_string($value)) {
            throw InvalidIncludeQuery::includesNotAllowed(collect(['include']), collect($allowed));
        }
        $includes = array_values(array_filter(array_map('trim', explode(',', $value))));
        $unknown = array_diff($includes, $allowed);
        if ($unknown !== []) {
            throw InvalidIncludeQuery::includesNotAllowed(collect($unknown), collect($allowed));
        }

        return $includes;
    }

    private function recordedUntil(string $value): Carbon
    {
        $recordedUntil = Carbon::parse($value);

        $recordedUntil = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? $recordedUntil->endOfDay()
            : $recordedUntil;

        return $recordedUntil->utc();
    }

    /** @param array<int, string> $includes
     * @return array<int, string>
     */
    private function relationshipIncludes(array $includes): array
    {
        return array_map(static fn (string $include): string => $include === 'recorded_by' ? 'recorder' : $include, $includes);
    }
}
