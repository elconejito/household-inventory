<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCategoryItemRequest;
use App\Models\Household;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CategoryItemController extends Controller
{
    public function update(UpdateCategoryItemRequest $request, string $category, string $item): JsonResponse
    {
        $household = $this->household($request->user());
        $assigned = (bool) $request->validated('data.assigned');

        DB::transaction(function () use ($household, $request, $category, $item, $assigned): void {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $request->user());
            $item = $household->items()->lockForUpdate()->findOrFail($item);
            $category = $household->categories()->lockForUpdate()->findOrFail($category);

            Gate::authorize('update', $item);
            Gate::authorize('update', $category);

            if ($assigned) {
                $item->categories()->syncWithoutDetaching([$category->getKey()]);

                return;
            }

            $item->categories()->detach($category->getKey());
        });

        return response()->json(['data' => null]);
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
}
