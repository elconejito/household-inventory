<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateInventoryAlert;
use App\Actions\ResolveInventoryAlert;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInventoryAlertRequest;
use App\Http\Requests\StoreInventoryAlertRequest;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\InventoryAlertTransformer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\QueryBuilder;

class InventoryAlertController extends Controller
{
    public function index(IndexInventoryAlertRequest $request, ApiResponse $apiResponse, InventoryAlertTransformer $transformer): JsonResponse
    {
        Gate::authorize('viewAny', InventoryAlert::class);
        $household = $this->household($request->user());
        $includes = $this->requestedIncludes($request->query('include'));
        $query = QueryBuilder::for($household->inventoryAlerts())
            ->allowedFilters(
                AllowedFilter::callback('status', static function (Builder $query, string $value): Builder {
                    return match ($value) {
                        'active' => $query->whereNull('resolved_at')->whereHas('item', fn (Builder $items): Builder => $items->whereNull('items.deleted_at')),
                        'resolved' => $query->whereNotNull('resolved_at'),
                        'all' => $query,
                    };
                }),
                AllowedFilter::exact('item_id'),
                AllowedFilter::exact('alert_type'),
            )
            ->allowedSorts('created_at')
            ->allowedIncludes(
                'item',
                AllowedInclude::relationship('created_by', 'creator'),
                AllowedInclude::relationship('resolved_by', 'resolver'),
            )
            ->orderByDesc('inventory_alerts.created_at')
            ->orderByDesc('inventory_alerts.id');
        $paginator = $query->paginate($request->integer('per_page', 10))->withQueryString();
        $response = $apiResponse->collection($paginator->items(), $transformer, $includes);
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

    public function store(StoreInventoryAlertRequest $request, CreateInventoryAlert $create, ApiResponse $apiResponse, InventoryAlertTransformer $transformer): JsonResponse
    {
        $includes = $this->requestedIncludes($request->query('include'));
        Gate::authorize('create', InventoryAlert::class);
        $alert = $create->create($this->household($request->user()), $request->user(), $request->validated('data'));
        $this->loadIncludes($alert, $includes);

        return response()->json($apiResponse->item($alert, $transformer, $includes), 201)
            ->header('Location', route('inventory-alerts.show', ['inventory_alert' => $alert->getKey()]));
    }

    public function show(Request $request, string $inventory_alert, ApiResponse $apiResponse, InventoryAlertTransformer $transformer): JsonResponse
    {
        $household = $this->household($request->user());
        $alert = $household->inventoryAlerts()->findOrFail($inventory_alert);
        Gate::authorize('view', $alert);
        $includes = $this->requestedIncludes($request->query('include'));
        $this->loadIncludes($alert, $includes);

        return response()->json($apiResponse->item($alert, $transformer, $includes));
    }

    public function resolve(Request $request, string $inventory_alert, ResolveInventoryAlert $resolve, ApiResponse $apiResponse, InventoryAlertTransformer $transformer): JsonResponse
    {
        $includes = $this->requestedIncludes($request->query('include'));
        if (array_merge($request->request->all(), $request->json()->all()) !== []) {
            throw ValidationException::withMessages(['body' => ['The request body must be empty.']]);
        }

        $household = $this->household($request->user());
        $alert = $household->inventoryAlerts()->findOrFail($inventory_alert);
        Gate::authorize('resolve', $alert);
        $alert = $resolve->resolve($household, $alert, $request->user());
        $this->loadIncludes($alert, $includes);

        return response()->json($apiResponse->item($alert, $transformer, $includes));
    }

    private function household(User $user): Household
    {
        return $user->households()->firstOrFail();
    }

    /** @return array<int, string> */
    private function requestedIncludes(mixed $value): array
    {
        $allowed = ['item', 'created_by', 'resolved_by'];
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

    /** @param array<int, string> $includes */
    private function loadIncludes(InventoryAlert $alert, array $includes): void
    {
        $relations = array_map(static fn (string $include): string => match ($include) {
            'created_by' => 'creator',
            'resolved_by' => 'resolver',
            default => $include,
        }, $includes);
        if ($relations !== []) {
            $alert->load($relations);
        }
    }
}
