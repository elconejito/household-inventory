<?php

namespace App\Http\Controllers\Api;

use App\Actions\ManageInventoryLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInventoryLevelRequest;
use App\Http\Requests\StoreInventoryLevelRequest;
use App\Http\Requests\UpdateInventoryLevelRequest;
use App\Models\Household;
use App\Models\InventoryLevel;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\InventoryLevelTransformer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\QueryBuilder;

class InventoryLevelController extends Controller
{
    public function index(IndexInventoryLevelRequest $request, ApiResponse $apiResponse, InventoryLevelTransformer $transformer): JsonResponse
    {
        Gate::authorize('viewAny', InventoryLevel::class);
        $household = $this->household($request->user());
        $includes = $this->requestedIncludes($request->query('include'));
        $query = QueryBuilder::for(InventoryLevel::query()
            ->whereHas('item', fn (Builder $query): Builder => $query->where('household_id', $household->getKey()))
            ->whereHas('location', fn (Builder $query): Builder => $query->where('household_id', $household->getKey())))
            ->allowedFilters(
                AllowedFilter::exact('item_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('quantity'),
                AllowedFilter::callback('alert_status', fn (Builder $query, mixed $value): Builder => $this->applyAlertStatusFilter($query, (string) $value)),
            )
            ->allowedIncludes('item', 'location')
            ->orderBy('inventory_levels.id');
        $paginator = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return response()->json($this->paginated($paginator, $apiResponse, $transformer, $includes));
    }

    public function store(StoreInventoryLevelRequest $request, ManageInventoryLevel $levels, ApiResponse $apiResponse, InventoryLevelTransformer $transformer): JsonResponse
    {
        $includes = $this->requestedIncludes($request->query('include'));
        Gate::authorize('create', InventoryLevel::class);
        $level = $levels->create($this->household($request->user()), $request->user(), $request->validated('data'));
        if ($includes !== []) {
            $level->load($includes);
        }

        return response()->json($apiResponse->item($level, $transformer, $includes), 201);
    }

    public function show(Request $request, string $inventory_level, ApiResponse $apiResponse, InventoryLevelTransformer $transformer): JsonResponse
    {
        $householdId = $this->household($request->user())->getKey();
        $level = InventoryLevel::query()->whereHas('item', fn (Builder $query): Builder => $query->where('household_id', $householdId))
            ->whereHas('location', fn (Builder $query): Builder => $query->where('household_id', $householdId))
            ->findOrFail($inventory_level);
        Gate::authorize('view', $level);
        $includes = $this->requestedIncludes($request->query('include'));
        if ($includes !== []) {
            $level->load($includes);
        }

        return response()->json($apiResponse->item($level, $transformer, $includes));
    }

    public function update(UpdateInventoryLevelRequest $request, string $inventory_level, ManageInventoryLevel $levels, ApiResponse $apiResponse, InventoryLevelTransformer $transformer): JsonResponse
    {
        $includes = $this->requestedIncludes($request->query('include'));
        $household = $this->household($request->user());
        $level = InventoryLevel::query()->whereHas('item', fn (Builder $query): Builder => $query->where('household_id', $household->getKey()))
            ->whereHas('location', fn (Builder $query): Builder => $query->where('household_id', $household->getKey()))
            ->findOrFail($inventory_level);
        Gate::authorize('update', $level);
        $level = $levels->update($household, $level, $request->user(), $request->validated('data'));
        if ($includes !== []) {
            $level->load($includes);
        }

        return response()->json($apiResponse->item($level, $transformer, $includes));
    }

    private function household(User $user): Household
    {
        return $user->households()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function paginated(LengthAwarePaginator $paginator, ApiResponse $apiResponse, InventoryLevelTransformer $transformer, array $includes): array
    {
        $response = $apiResponse->collection($paginator->items(), $transformer, $includes);
        $response['meta'] = ['current_page' => $paginator->currentPage(), 'from' => $paginator->firstItem(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()];
        $response['links'] = ['first' => $paginator->url(1), 'last' => $paginator->url($paginator->lastPage()), 'prev' => $paginator->previousPageUrl(), 'next' => $paginator->nextPageUrl()];

        return $response;
    }

    /** @return array<int, string> */
    private function requestedIncludes(mixed $value): array
    {
        $allowed = ['item', 'location'];
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

    private function applyAlertStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'triggered' => $query->whereNotNull('alert_threshold')->whereColumn('quantity', '<=', 'alert_threshold'),
            'empty' => $query->whereNotNull('alert_threshold')->where('quantity', 0),
            'low' => $query->whereNotNull('alert_threshold')->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'alert_threshold'),
            'okay' => $query->whereNotNull('alert_threshold')->whereColumn('quantity', '>', 'alert_threshold'),
            'unmonitored' => $query->whereNull('alert_threshold'),
        };
    }
}
