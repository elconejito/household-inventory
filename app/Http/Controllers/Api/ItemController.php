<?php

namespace App\Http\Controllers\Api;

use App\Actions\ArchiveItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexItemRequest;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Models\Household;
use App\Models\Item;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\ItemTransformer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

class ItemController extends Controller
{
    public function index(
        IndexItemRequest $request,
        ApiResponse $apiResponse,
        ItemTransformer $transformer,
    ): JsonResponse {
        Gate::authorize('viewAny', Item::class);

        $household = $this->household($request->user());
        $includes = $this->requestedIncludes($request->query('include'));

        $query = QueryBuilder::for($household->items()->withInventorySummary())
            ->allowedFilters(
                AllowedFilter::partial('search', 'name'),
                AllowedFilter::partial('name'),
                AllowedFilter::trashed(),
            )
            ->allowedSorts('name')
            ->defaultSort('name')
            ->allowedIncludes(
                'categories',
                AllowedInclude::callback('inventory_levels',
                    fn (Builder|Relation $levels) => $levels->whereHas('location'), 'inventoryLevels'),
                AllowedInclude::callback('inventory_levels.location',
                    fn (Builder|Relation $levels) => $levels->whereHas('location')->with('location'), 'inventoryLevels'),
            )
            ->orderBy('items.id');

        $paginator = $query
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();
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

    public function store(
        StoreItemRequest $request,
        ApiResponse $apiResponse,
        ItemTransformer $transformer,
    ): JsonResponse {
        Gate::authorize('create', Item::class);

        $household = $this->household($request->user());
        /** @var array{name: string, counting_unit: string, description?: string|null, category_ids?: array<int, int|string>} $data */
        $data = $request->validated('data');
        $categoryIds = $data['category_ids'] ?? null;
        unset($data['category_ids']);
        $includes = $this->requestedIncludes($request->query('include'));

        $item = DB::transaction(function () use ($household, $data, $categoryIds): Item {
            $item = $household->items()->create($data);

            if ($categoryIds !== null) {
                $item->categories()->sync($categoryIds);
            }

            return $item;
        });

        $this->loadPresentation($item, $includes);

        return response()
            ->json($apiResponse->item($item, $transformer, $includes), 201)
            ->header('Location', route('items.show', ['item' => $item->id]));
    }

    public function show(
        Request $request,
        string $item,
        ApiResponse $apiResponse,
        ItemTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $item = $household->items()->withInventorySummary()->findOrFail($item);

        Gate::authorize('view', $item);
        $includes = $this->requestedIncludes($request->query('include'));

        $this->loadPresentation($item, $includes);

        return response()->json($apiResponse->item($item, $transformer, $includes));
    }

    public function update(
        UpdateItemRequest $request,
        string $item,
        ApiResponse $apiResponse,
        ItemTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $item = $household->items()->findOrFail($item);

        Gate::authorize('update', $item);
        /** @var array{name?: string, counting_unit?: string, description?: string|null, category_ids?: array<int, int|string>} $data */
        $data = $request->validated('data');
        $hasCategoryIds = array_key_exists('category_ids', $data);
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids']);
        $includes = $this->requestedIncludes($request->query('include'));

        DB::transaction(function () use ($item, $data, $hasCategoryIds, $categoryIds): void {
            $item->update($data);

            if ($hasCategoryIds) {
                $item->categories()->sync($categoryIds);
            }
        });

        $this->loadPresentation($item, $includes);

        return response()->json($apiResponse->item($item, $transformer, $includes));
    }

    public function destroy(Request $request, string $item, ArchiveItem $archive): Response
    {
        $household = $this->household($request->user());
        $item = $household->items()->findOrFail($item);

        Gate::authorize('delete', $item);
        $archive->archive($household, $item);

        return response()->noContent();
    }

    public function restore(
        Request $request,
        string $item,
        ApiResponse $apiResponse,
        ItemTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $item = $household->items()->withTrashed()->findOrFail($item);

        Gate::authorize('restore', $item);

        abort_unless($item->trashed(), 409);

        $includes = $this->requestedIncludes($request->query('include'));
        $item->restore();

        $this->loadPresentation($item, $includes);

        return response()->json($apiResponse->item($item, $transformer, $includes));
    }

    private function household(User $user): Household
    {
        return $user->households()->firstOrFail();
    }

    /**
     * @return array<int, string>
     */
    private function requestedIncludes(mixed $includeParameter): array
    {
        if ($includeParameter === null || $includeParameter === '') {
            return [];
        }

        $allowedIncludes = ['categories', 'inventory_levels', 'inventory_levels.location'];
        if (! is_string($includeParameter)) {
            throw InvalidIncludeQuery::includesNotAllowed(collect(['include']), collect($allowedIncludes));
        }

        $includes = array_values(array_filter(array_map('trim', explode(',', $includeParameter))));
        $unknownIncludes = array_diff($includes, $allowedIncludes);

        if ($unknownIncludes !== []) {
            throw InvalidIncludeQuery::includesNotAllowed(collect($unknownIncludes), collect($allowedIncludes));
        }

        return $includes;
    }

    /**
     * @param  array<int, string>  $includes
     */
    private function loadPresentation(Item $item, array $includes): void
    {
        if (! array_key_exists('total_quantity', $item->getAttributes())) {
            $item->loadSum([
                'inventoryLevels as total_quantity' => fn (Builder $levels) => $levels->whereHas('location'),
            ], 'quantity');
        }

        if (in_array('categories', $includes, true)) {
            $item->load('categories');
        }

        if (in_array('inventory_levels', $includes, true) || in_array('inventory_levels.location', $includes, true)) {
            $item->load(['inventoryLevels' => function (Builder|Relation $levels) use ($includes): void {
                $levels->whereHas('location');

                if (in_array('inventory_levels.location', $includes, true)) {
                    $levels->with('location');
                }
            }]);
        }
    }
}
