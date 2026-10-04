<?php

namespace App\Http\Controllers\Api;

use App\Actions\ArchiveItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexItemRequest;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Models\Household;
use App\Models\Item;
use App\Models\Membership;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\ItemTransformer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
                AllowedFilter::callback('category_id', fn (Builder $query, mixed $value): Builder => $query->whereHas(
                    'categories',
                    fn (Builder $categories): Builder => $categories
                        ->where('categories.household_id', $household->getKey())
                        ->whereKey($value),
                )),
                AllowedFilter::callback('location_id', fn (Builder $query, mixed $value): Builder => $query->whereHas(
                    'inventoryLevels',
                    fn (Builder $levels): Builder => $levels
                        ->where('location_id', $value)
                        ->whereHas('location', fn (Builder $locations): Builder => $locations
                            ->where('household_id', $household->getKey())),
                )),
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
                AllowedInclude::callback('active_alerts',
                    fn (Builder|Relation $alerts) => $alerts->whereNull('resolved_at'), 'inventoryAlerts'),
                'notes',
                AllowedInclude::relationship('notes.created_by', 'notes.creator'),
                'images',
                AllowedInclude::relationship('images.uploaded_by', 'images.uploader'),
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

        $item = DB::transaction(function () use ($household, $request, $data, $categoryIds): Item {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $request->user());
            Gate::authorize('create', Item::class);
            $this->assertCategoriesCanBeAttached($household, $categoryIds);
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

        $item = DB::transaction(function () use ($household, $item, $request, $data, $hasCategoryIds, $categoryIds): Item {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $request->user());
            $lockedItem = $household->items()->lockForUpdate()->findOrFail($item->getKey());
            Gate::authorize('update', $lockedItem);
            if ($hasCategoryIds) {
                $this->assertCategoriesCanBeAttached($household, $categoryIds);
            }
            $lockedItem->update($data);

            if ($hasCategoryIds) {
                $lockedItem->categories()->sync($categoryIds);
            }

            return $lockedItem;
        });

        $this->loadPresentation($item, $includes);

        return response()->json($apiResponse->item($item, $transformer, $includes));
    }

    public function destroy(Request $request, string $item, ArchiveItem $archive): Response
    {
        $household = $this->household($request->user());
        $item = $household->items()->findOrFail($item);

        Gate::authorize('delete', $item);
        $archive->archive($household, $item, $request->user());

        return response()->noContent();
    }

    public function restore(
        Request $request,
        string $item,
        ApiResponse $apiResponse,
        ItemTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $includes = $this->requestedIncludes($request->query('include'));
        $item = DB::transaction(function () use ($household, $item, $request): Item {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $request->user());
            $item = $household->items()->withTrashed()->lockForUpdate()->findOrFail($item);
            Gate::authorize('restore', $item);
            abort_unless($item->trashed(), 409);
            $item->restore();

            return $item;
        });

        $this->loadPresentation($item, $includes);

        return response()->json($apiResponse->item($item, $transformer, $includes));
    }

    private function household(User $user): Household
    {
        return $user->households()->firstOrFail();
    }

    private function assertActiveMembership(Household $household, User $user): void
    {
        $isActiveMember = Membership::query()
            ->where('household_id', $household->getKey())
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->exists();

        abort_unless($isActiveMember, 403);
    }

    /** @param array<int, int|string>|null $categoryIds */
    private function assertCategoriesCanBeAttached(Household $household, ?array $categoryIds): void
    {
        if ($categoryIds === null || $categoryIds === []) {
            return;
        }

        $activeCategoryCount = $household->categories()
            ->whereKey($categoryIds)
            ->lockForUpdate()
            ->count();

        if ($activeCategoryCount !== count($categoryIds)) {
            throw ValidationException::withMessages([
                'data.category_ids' => ['One or more selected categories are invalid or archived.'],
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function requestedIncludes(mixed $includeParameter): array
    {
        if ($includeParameter === null || $includeParameter === '') {
            return [];
        }

        $allowedIncludes = ['categories', 'inventory_levels', 'inventory_levels.location', 'notes', 'notes.created_by', 'images', 'images.uploaded_by', 'active_alerts'];
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

        if (in_array('active_alerts', $includes, true) && ! $item->relationLoaded('inventoryAlerts')) {
            $item->load(['inventoryAlerts' => fn (Builder|Relation $alerts) => $alerts->whereNull('resolved_at')]);
        }

        if (in_array('inventory_levels', $includes, true) || in_array('inventory_levels.location', $includes, true)) {
            $item->load(['inventoryLevels' => function (Builder|Relation $levels) use ($includes): void {
                $levels->whereHas('location');

                if (in_array('inventory_levels.location', $includes, true)) {
                    $levels->with('location');
                }
            }]);
        }

        if (in_array('notes', $includes, true) || in_array('notes.created_by', $includes, true)) {
            $item->load(array_map(static fn (string $include): string => $include === 'notes.created_by' ? 'notes.creator' : $include, array_filter($includes, static fn (string $include): bool => str_starts_with($include, 'notes'))));
        }

        if (in_array('images', $includes, true) || in_array('images.uploaded_by', $includes, true)) {
            $item->load(in_array('images.uploaded_by', $includes, true) ? 'images.uploader' : 'images');
        }
    }
}
