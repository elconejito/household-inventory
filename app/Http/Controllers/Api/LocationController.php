<?php

namespace App\Http\Controllers\Api;

use App\Actions\ManageLocationHierarchy;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexLocationRequest;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Household;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\LocationTransformer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

class LocationController extends Controller
{
    public function index(
        IndexLocationRequest $request,
        ApiResponse $apiResponse,
        LocationTransformer $transformer,
    ): JsonResponse {
        Gate::authorize('viewAny', Location::class);

        $household = $this->household($request->user());
        $includes = $this->requestedIncludes($request->query('include'));

        $query = QueryBuilder::for($household->locations()->getQuery())
            ->allowedFilters(
                AllowedFilter::partial('search', 'name'),
                AllowedFilter::partial('name'),
                AllowedFilter::callback('parent_id', fn (Builder $query, mixed $value): Builder => $value === 'root'
                    ? $query->whereNull('parent_id')
                    : $query->where('parent_id', $value)),
                AllowedFilter::trashed(),
            )
            ->allowedSorts('name')
            ->defaultSort('name')
            ->allowedIncludes('parent', 'children', 'notes', AllowedInclude::relationship('notes.created_by', 'notes.creator'))
            ->orderBy('locations.id');

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
        StoreLocationRequest $request,
        ManageLocationHierarchy $hierarchy,
        ApiResponse $apiResponse,
        LocationTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        /** @var array{name: string, description?: string|null, parent_id?: int|string|null} $data */
        $data = $request->validated('data');
        $includes = $this->requestedIncludes($request->query('include'));
        $location = DB::transaction(function () use ($household, $request, $hierarchy, $data): Location {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $request->user());
            Gate::authorize('create', Location::class);

            return $hierarchy->create($household, $data);
        });

        if ($includes !== []) {
            $location->load($this->relationshipIncludes($includes));
        }

        return response()
            ->json($apiResponse->item($location, $transformer, $includes), 201)
            ->header('Location', route('locations.show', ['location' => $location->id]));
    }

    public function show(
        Request $request,
        string $location,
        ApiResponse $apiResponse,
        LocationTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $location = $household->locations()->findOrFail($location);

        Gate::authorize('view', $location);
        $includes = $this->requestedIncludes($request->query('include'));

        if ($includes !== []) {
            $location->load($this->relationshipIncludes($includes));
        }

        return response()->json($apiResponse->item($location, $transformer, $includes));
    }

    public function update(
        UpdateLocationRequest $request,
        string $location,
        ManageLocationHierarchy $hierarchy,
        ApiResponse $apiResponse,
        LocationTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        /** @var array{name?: string, description?: string|null, parent_id?: int|string|null} $data */
        $data = $request->validated('data');
        $includes = $this->requestedIncludes($request->query('include'));
        $location = DB::transaction(function () use ($household, $location, $request, $hierarchy, $data): Location {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $request->user());
            $location = $household->locations()->lockForUpdate()->findOrFail($location);
            Gate::authorize('update', $location);

            return $hierarchy->update($household, $location, $data);
        });

        if ($includes !== []) {
            $location->load($this->relationshipIncludes($includes));
        }

        return response()->json($apiResponse->item($location, $transformer, $includes));
    }

    public function destroy(
        Request $request,
        string $location,
        ManageLocationHierarchy $hierarchy,
    ): Response {
        $household = $this->household($request->user());
        DB::transaction(function () use ($household, $location, $request, $hierarchy): void {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $request->user());
            $location = $household->locations()->lockForUpdate()->findOrFail($location);
            Gate::authorize('delete', $location);
            $hierarchy->archive($household, $location);
        });

        return response()->noContent();
    }

    public function restore(
        Request $request,
        string $location,
        ManageLocationHierarchy $hierarchy,
        ApiResponse $apiResponse,
        LocationTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $includes = $this->requestedIncludes($request->query('include'));
        $location = DB::transaction(function () use ($household, $location, $request, $hierarchy): Location {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $request->user());
            $location = $household->locations()->withTrashed()->lockForUpdate()->findOrFail($location);
            Gate::authorize('restore', $location);

            return $hierarchy->restore($household, $location);
        });

        if ($includes !== []) {
            $location->load($this->relationshipIncludes($includes));
        }

        return response()->json($apiResponse->item($location, $transformer, $includes));
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

    /**
     * @return array<int, string>
     */
    private function requestedIncludes(mixed $includeParameter): array
    {
        if ($includeParameter === null || $includeParameter === '') {
            return [];
        }

        $allowedIncludes = ['parent', 'children', 'notes', 'notes.created_by'];
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

    /** @param array<int, string> $includes
     * @return array<int, string>
     */
    private function relationshipIncludes(array $includes): array
    {
        return array_map(static fn (string $include): string => $include === 'notes.created_by' ? 'notes.creator' : $include, $includes);
    }
}
