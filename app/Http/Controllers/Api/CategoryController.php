<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCategoryRequest;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\CategoryTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    public function index(
        IndexCategoryRequest $request,
        ApiResponse $apiResponse,
        CategoryTransformer $transformer,
    ): JsonResponse {
        Gate::authorize('viewAny', Category::class);

        $household = $this->household($request->user());
        $includes = $this->requestedIncludes($request->query('include'));

        $query = QueryBuilder::for($household->categories()->getQuery())
            ->allowedFilters(
                AllowedFilter::partial('search', 'name'),
                AllowedFilter::partial('name'),
                AllowedFilter::trashed(),
            )
            ->allowedSorts('name')
            ->defaultSort('name')
            ->allowedIncludes('items')
            ->orderBy('categories.id');

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
        StoreCategoryRequest $request,
        ApiResponse $apiResponse,
        CategoryTransformer $transformer,
    ): JsonResponse {
        Gate::authorize('create', Category::class);

        $household = $this->household($request->user());
        /** @var array{name: string} $data */
        $data = $request->validated('data');
        $includes = $this->requestedIncludes($request->query('include'));
        $category = $household->categories()->create($data);

        if (in_array('items', $includes, true)) {
            $category->load('items');
        }

        return response()
            ->json($apiResponse->item($category, $transformer, $includes), 201)
            ->header('Location', route('categories.show', ['category' => $category->id]));
    }

    public function show(
        Request $request,
        string $category,
        ApiResponse $apiResponse,
        CategoryTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $category = $household->categories()->findOrFail($category);

        Gate::authorize('view', $category);
        $includes = $this->requestedIncludes($request->query('include'));

        if (in_array('items', $includes, true)) {
            $category->load('items');
        }

        return response()->json($apiResponse->item($category, $transformer, $includes));
    }

    public function update(
        UpdateCategoryRequest $request,
        string $category,
        ApiResponse $apiResponse,
        CategoryTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $category = $household->categories()->findOrFail($category);

        Gate::authorize('update', $category);
        /** @var array{name?: string} $data */
        $data = $request->validated('data');
        $includes = $this->requestedIncludes($request->query('include'));
        $category->update($data);

        if (in_array('items', $includes, true)) {
            $category->load('items');
        }

        return response()->json($apiResponse->item($category, $transformer, $includes));
    }

    public function destroy(Request $request, string $category): Response
    {
        $household = $this->household($request->user());
        $category = $household->categories()->findOrFail($category);

        Gate::authorize('delete', $category);
        $category->delete();

        return response()->noContent();
    }

    public function restore(
        Request $request,
        string $category,
        ApiResponse $apiResponse,
        CategoryTransformer $transformer,
    ): JsonResponse {
        $household = $this->household($request->user());
        $category = $household->categories()->withTrashed()->findOrFail($category);

        Gate::authorize('restore', $category);
        abort_unless($category->trashed(), 409);

        $includes = $this->requestedIncludes($request->query('include'));
        $category->restore();

        if (in_array('items', $includes, true)) {
            $category->load('items');
        }

        return response()->json($apiResponse->item($category, $transformer, $includes));
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

        $allowedIncludes = ['items'];
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
}
