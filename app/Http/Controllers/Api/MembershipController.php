<?php

namespace App\Http\Controllers\Api;

use App\Actions\ManageMembership;
use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexMembershipRequest;
use App\Http\Requests\UpdateMembershipRequest;
use App\Serialization\ApiResponse;
use App\Transformers\MembershipTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

class MembershipController extends Controller
{
    public function index(IndexMembershipRequest $request, ApiResponse $apiResponse, MembershipTransformer $transformer): JsonResponse
    {
        $household = $request->user()->households()->firstOrFail();
        Gate::authorize('manage', $household);
        $query = QueryBuilder::for($household->memberships()->getQuery())
            ->allowedFilters(AllowedFilter::trashed())
            ->allowedSorts('role', 'joined_at')
            ->defaultSort('joined_at')
            ->allowedIncludes('user')
            ->orderBy('memberships.id');
        $paginator = $query->paginate($request->integer('per_page', 10))->withQueryString();
        $response = $apiResponse->collection($paginator->items(), $transformer, $this->includes($request));
        $response['meta'] = [
            'current_page' => $paginator->currentPage(), 'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(), 'total' => $paginator->total(),
        ];
        $response['links'] = [
            'first' => $paginator->url(1), 'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(), 'next' => $paginator->nextPageUrl(),
        ];

        return response()->json($response);
    }

    public function update(UpdateMembershipRequest $request, string $membership, ManageMembership $manage, ApiResponse $apiResponse, MembershipTransformer $transformer): JsonResponse
    {
        $includes = $this->requestedIncludes($request->query('include'));
        $household = $request->user()->households()->firstOrFail();
        $membership = $household->memberships()->findOrFail($membership);
        Gate::authorize('manage', $membership);
        $membership = $manage->update($household, $membership, MembershipRole::from($request->validated('data.role')), $request->user());

        if ($includes === ['user']) {
            $membership->load('user');
        }

        return response()->json($apiResponse->item($membership, $transformer, $includes));
    }

    public function destroy(Request $request, string $membership, ManageMembership $manage): Response
    {
        $this->assertNoIncludes($request->query('include'));
        $household = $request->user()->households()->firstOrFail();
        $membership = $household->memberships()->findOrFail($membership);
        Gate::authorize('manage', $membership);
        $manage->remove($household, $membership, $request->user());

        return response()->noContent();
    }

    public function restore(Request $request, string $membership, ManageMembership $manage, ApiResponse $apiResponse, MembershipTransformer $transformer): JsonResponse
    {
        $includes = $this->requestedIncludes($request->query('include'));
        $household = $request->user()->households()->firstOrFail();
        $membership = $household->memberships()->withTrashed()->findOrFail($membership);
        Gate::authorize('manage', $membership);
        $membership = $manage->restore($household, $membership, $request->user());

        if ($includes === ['user']) {
            $membership->load('user');
        }

        return response()->json($apiResponse->item($membership, $transformer, $includes));
    }

    public function leave(Request $request, ManageMembership $manage): Response
    {
        $this->assertNoIncludes($request->query('include'));
        $membership = $request->user()->memberships()->firstOrFail();
        Gate::authorize('leave', $membership);
        $manage->leave($membership->household, $membership);

        return response()->noContent();
    }

    /** @return array<int, string> */
    private function includes(IndexMembershipRequest $request): array
    {
        return $this->requestedIncludes($request->query('include'));
    }

    /** @return array<int, string> */
    private function requestedIncludes(mixed $include): array
    {
        if ($include === null || $include === '') {
            return [];
        }

        if (! is_string($include)) {
            throw InvalidIncludeQuery::includesNotAllowed(collect(['include']), collect(['user']));
        }

        $includes = array_values(array_filter(array_map('trim', explode(',', $include))));
        $unknownIncludes = array_diff($includes, ['user']);
        if ($unknownIncludes !== []) {
            throw InvalidIncludeQuery::includesNotAllowed(collect($unknownIncludes), collect(['user']));
        }

        return $includes;
    }

    private function assertNoIncludes(mixed $include): void
    {
        if ($include !== null && $include !== '') {
            throw InvalidIncludeQuery::includesNotAllowed(collect(is_string($include) ? explode(',', $include) : ['include']), collect([]));
        }
    }
}
