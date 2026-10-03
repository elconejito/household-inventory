<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateHouseholdRequest;
use App\Models\Household;
use App\Serialization\ApiResponse;
use App\Transformers\HouseholdTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;

class HouseholdController extends Controller
{
    public function show(Request $request, ApiResponse $apiResponse, HouseholdTransformer $transformer): JsonResponse
    {
        $this->assertNoIncludes($request->query('include'));
        $household = $request->user()->households()->firstOrFail();
        Gate::authorize('manage', $household);

        return response()->json($apiResponse->item($household, $transformer));
    }

    public function update(UpdateHouseholdRequest $request, ApiResponse $apiResponse, HouseholdTransformer $transformer): JsonResponse
    {
        $this->assertNoIncludes($request->query('include'));
        $household = $request->user()->households()->firstOrFail();
        Gate::authorize('manage', $household);
        $household = DB::transaction(function () use ($household, $request): Household {
            $lockedHousehold = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            Gate::authorize('manage', $lockedHousehold);
            $lockedHousehold->update($request->validated('data'));

            return $lockedHousehold;
        });

        return response()->json($apiResponse->item($household, $transformer));
    }

    private function assertNoIncludes(mixed $include): void
    {
        if ($include !== null && $include !== '') {
            throw InvalidIncludeQuery::includesNotAllowed(collect(is_string($include) ? explode(',', $include) : ['include']), collect([]));
        }
    }
}
