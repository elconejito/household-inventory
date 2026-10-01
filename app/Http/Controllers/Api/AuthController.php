<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoginRequest;
use App\Http\Requests\StoreRegistrationRequest;
use App\Models\Household;
use App\Models\Membership;
use App\Models\User;
use App\Serialization\ApiResponse;
use App\Transformers\AuthStatusTransformer;
use App\Transformers\UserTransformer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;

class AuthController extends Controller
{
    public function register(
        StoreRegistrationRequest $request,
        ApiResponse $apiResponse,
        UserTransformer $transformer,
    ): JsonResponse {
        /** @var array{name: string, email: string, password: string, password_confirmation: string, household_name: string} $data */
        $data = $request->validated('data');

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $household = Household::create(['name' => $data['household_name']]);

            Membership::create([
                'household_id' => $household->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->userResponse($request, $user, $apiResponse, $transformer, 201);
    }

    public function login(
        StoreLoginRequest $request,
        ApiResponse $apiResponse,
        UserTransformer $transformer,
    ): JsonResponse {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated('data');

        if (! Auth::guard('web')->attempt($credentials)) {
            throw new AuthenticationException;
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $this->userResponse($request, $user, $apiResponse, $transformer);
    }

    public function show(Request $request, ApiResponse $apiResponse, UserTransformer $transformer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->userResponse($request, $user, $apiResponse, $transformer);
    }

    public function logout(Request $request, ApiResponse $apiResponse, AuthStatusTransformer $transformer): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json($apiResponse->item(['status' => 'logged_out'], $transformer));
    }

    private function userResponse(
        Request $request,
        User $user,
        ApiResponse $apiResponse,
        UserTransformer $transformer,
        int $status = 200,
    ): JsonResponse {
        $includes = $this->requestedIncludes($request);

        return response()->json($apiResponse->item($user, $transformer, $includes), $status);
    }

    /**
     * @return array<int, string>
     */
    private function requestedIncludes(Request $request): array
    {
        $includeParameter = $request->query('include');

        if ($includeParameter === null || $includeParameter === '') {
            return [];
        }

        $allowedIncludes = ['membership', 'membership.household'];
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
