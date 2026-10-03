<?php

namespace App\Http\Controllers\Api;

use App\Actions\ManageHouseholdInvitations;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptHouseholdInvitationRequest;
use App\Http\Requests\IndexHouseholdInvitationRequest;
use App\Http\Requests\StoreHouseholdInvitationRequest;
use App\Serialization\ApiResponse;
use App\Transformers\HouseholdInvitationTransformer;
use App\Transformers\UserTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

class HouseholdInvitationController extends Controller
{
    public function index(IndexHouseholdInvitationRequest $request, ApiResponse $apiResponse, HouseholdInvitationTransformer $transformer): JsonResponse
    {
        $household = $request->user()->households()->firstOrFail();
        Gate::authorize('manage', $household);
        $paginator = QueryBuilder::for($household->invitations())
            ->allowedFilters(AllowedFilter::exact('email'))
            ->allowedSorts('email', 'created_at', 'expires_at')
            ->defaultSort('-created_at')
            ->allowedIncludes()
            ->orderBy('household_invitations.id')
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();
        $response = $apiResponse->collection($paginator->items(), $transformer);
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

    public function store(StoreHouseholdInvitationRequest $request, ManageHouseholdInvitations $manage, ApiResponse $apiResponse, HouseholdInvitationTransformer $transformer): JsonResponse
    {
        $this->assertNoIncludes($request->query('include'));
        $household = $request->user()->households()->firstOrFail();
        Gate::authorize('manage', $household);
        $invitation = $manage->create($household, $request->user(), $request->validated('data.email'));

        return response()->json($apiResponse->item($invitation, $transformer), 201);
    }

    public function resend(Request $request, string $household_invitation, ManageHouseholdInvitations $manage, ApiResponse $apiResponse, HouseholdInvitationTransformer $transformer): JsonResponse
    {
        $this->assertNoIncludes($request->query('include'));
        $household = $request->user()->households()->firstOrFail();
        $invitation = $household->invitations()->findOrFail($household_invitation);
        Gate::authorize('manage', $invitation);
        $invitation = $manage->resend($household, $invitation, $request->user());

        return response()->json($apiResponse->item($invitation, $transformer), 200);
    }

    public function destroy(Request $request, string $household_invitation, ManageHouseholdInvitations $manage): Response
    {
        $this->assertNoIncludes($request->query('include'));
        $household = $request->user()->households()->firstOrFail();
        $invitation = $household->invitations()->findOrFail($household_invitation);
        Gate::authorize('manage', $invitation);
        $manage->revoke($household, $invitation, $request->user());

        return response()->noContent();
    }

    public function accept(AcceptHouseholdInvitationRequest $request, ManageHouseholdInvitations $manage, ApiResponse $apiResponse, UserTransformer $transformer): JsonResponse
    {
        $includes = $this->acceptIncludes($request->query('include'));
        $isGuest = $request->user() === null;
        $user = $manage->accept($request->validated('data.token'), $request->user(), $request->validated('data'));

        if ($isGuest) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        if (in_array('membership.household', $includes, true)) {
            $user->load('memberships.household');
        }

        return response()->json($apiResponse->item($user, $transformer, $includes));
    }

    /** @return array<int, string> */
    private function acceptIncludes(mixed $include): array
    {
        if ($include === null || $include === '') {
            return [];
        }

        $allowedIncludes = ['membership', 'membership.household'];
        if (! is_string($include)) {
            throw InvalidIncludeQuery::includesNotAllowed(collect(['include']), collect($allowedIncludes));
        }

        $includes = array_values(array_filter(array_map('trim', explode(',', $include))));
        $unknownIncludes = array_diff($includes, $allowedIncludes);
        if ($unknownIncludes !== []) {
            throw InvalidIncludeQuery::includesNotAllowed(collect($unknownIncludes), collect($allowedIncludes));
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
