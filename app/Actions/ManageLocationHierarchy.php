<?php

namespace App\Actions;

use App\Models\Household;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageLocationHierarchy
{
    /**
     * @param  array{name: string, description?: string|null, parent_id?: int|string|null}  $data
     */
    public function create(Household $household, array $data): Location
    {
        return DB::transaction(function () use ($household, $data): Location {
            $household = $this->lockHousehold($household);
            $name = Str::squish($data['name']);
            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

            $this->assertParentIsActiveInHousehold($household, $parentId);
            $this->assertNameIsAvailable($household, $name, $parentId);

            $location = $household->locations()->make([
                'name' => $name,
                'description' => $data['description'] ?? null,
            ]);
            $location->parent_id = $parentId;
            $household->locations()->save($location);

            return $location;
        });
    }

    /**
     * @param  array{name?: string, description?: string|null, parent_id?: int|string|null}  $data
     */
    public function update(Household $household, Location $location, array $data): Location
    {
        return DB::transaction(function () use ($household, $location, $data): Location {
            $household = $this->lockHousehold($household);
            $location = $household->locations()->lockForUpdate()->findOrFail($location->getKey());
            $name = Str::squish($data['name'] ?? $location->name);
            $parentId = array_key_exists('parent_id', $data)
                ? (isset($data['parent_id']) ? (int) $data['parent_id'] : null)
                : $location->parent_id;

            $this->assertParentIsActiveInHousehold($household, $parentId);
            $this->assertNotMovedBeneathSelfOrDescendant($household, $location, $parentId);
            $this->assertNameIsAvailable($household, $name, $parentId, $location->getKey());

            $location->name = $name;
            $location->parent_id = $parentId;

            if (array_key_exists('description', $data)) {
                $location->description = $data['description'];
            }

            $location->save();

            return $location;
        });
    }

    public function archive(Household $household, Location $location): void
    {
        DB::transaction(function () use ($household, $location): void {
            $household = $this->lockHousehold($household);
            $location = $household->locations()->lockForUpdate()->findOrFail($location->getKey());

            if ($household->locations()->where('parent_id', $location->getKey())->exists()) {
                abort(409);
            }

            $location->delete();
        });
    }

    public function restore(Household $household, Location $location): Location
    {
        return DB::transaction(function () use ($household, $location): Location {
            $household = $this->lockHousehold($household);
            $location = $household->locations()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($location->getKey());

            abort_unless($location->trashed(), 409);

            if ($location->parent_id !== null) {
                $parentIsActive = $household->locations()->whereKey($location->parent_id)->exists();
                abort_unless($parentIsActive, 409);
            }

            if ($this->nameIsTaken($household, $location->name, $location->parent_id, $location->getKey())) {
                abort(409);
            }

            $location->restore();

            return $location;
        });
    }

    private function lockHousehold(Household $household): Household
    {
        return Household::query()->lockForUpdate()->findOrFail($household->getKey());
    }

    private function assertParentIsActiveInHousehold(Household $household, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parentIsActive = $household->locations()
            ->whereKey($parentId)
            ->lockForUpdate()
            ->exists();

        if (! $parentIsActive) {
            $this->failValidation('data.parent_id', 'The selected parent location is invalid.');
        }
    }

    private function assertNotMovedBeneathSelfOrDescendant(
        Household $household,
        Location $location,
        ?int $parentId,
    ): void {
        if ($parentId === null) {
            return;
        }

        if ($parentId === (int) $location->getKey()) {
            $this->failValidation('data.parent_id', 'A location cannot be its own parent.');
        }

        if (in_array($parentId, $this->descendantIds($household, (int) $location->getKey()), true)) {
            $this->failValidation('data.parent_id', 'A location cannot be moved beneath one of its descendants.');
        }
    }

    /**
     * @return array<int, int>
     */
    private function descendantIds(Household $household, int $locationId): array
    {
        $visited = [$locationId => true];
        $frontier = [$locationId];

        while ($frontier !== []) {
            $children = Location::withTrashed()
                ->where('household_id', $household->getKey())
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(static fn (int|string $id): int => (int) $id)
                ->all();

            $frontier = [];
            foreach ($children as $childId) {
                if (! isset($visited[$childId])) {
                    $visited[$childId] = true;
                    $frontier[] = $childId;
                }
            }
        }

        unset($visited[$locationId]);

        return array_keys($visited);
    }

    private function assertNameIsAvailable(
        Household $household,
        string $name,
        ?int $parentId,
        ?int $exceptLocationId = null,
    ): void {
        if ($this->nameIsTaken($household, $name, $parentId, $exceptLocationId)) {
            $this->failValidation('data.name', 'The data.name has already been taken.');
        }
    }

    private function nameIsTaken(
        Household $household,
        string $name,
        ?int $parentId,
        ?int $exceptLocationId = null,
    ): bool {
        $query = Location::withTrashed()
            ->where('household_id', $household->getKey())
            ->whereRaw('LOWER(name) = LOWER(?)', [$name]);

        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }

        if ($exceptLocationId !== null) {
            $query->where('id', '!=', $exceptLocationId);
        }

        return $query->exists();
    }

    private function failValidation(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => [$message]]);
    }
}
