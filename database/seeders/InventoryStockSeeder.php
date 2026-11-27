<?php

namespace Database\Seeders;

use App\Actions\CreateInventoryAlert;
use App\Actions\CreateInventoryMovement;
use App\Actions\ResolveInventoryAlert;
use App\Enums\InventoryAlertType;
use App\Models\Category;
use App\Models\Household;
use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class InventoryStockSeeder extends Seeder
{
    public function run(
        CreateInventoryMovement $movements,
        CreateInventoryAlert $alerts,
        ?ResolveInventoryAlert $resolveAlerts = null,
        ?ItemImageSeeder $images = null,
    ): void {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Inventory demo data can only be seeded in local or testing environments.');
        }

        $resolveAlerts ??= app(ResolveInventoryAlert::class);
        $images ??= app(ItemImageSeeder::class);

        DB::transaction(function () use ($movements, $alerts, $resolveAlerts, $images): void {
            $user = User::query()->firstOrCreate(
                ['email' => 'inventory-demo@example.test'],
                ['name' => 'Inventory Demo', 'password' => 'password'],
            );
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $household = $this->householdForDemoUser($user);

            $migratedLocations = $this->renameLegacyRecords($household);
            $categories = $this->seedCategories($household);
            $locations = $this->seedLocations($household, $migratedLocations);
            $this->seedItems($household, $user, $categories, $locations, $movements);
            $this->seedAlerts($household, $user, $alerts, $resolveAlerts);
            DemoHouseholdDetails::seed($household, $user);
            $images->seedDemoImage($household, $user);
        });
    }

    /** @return list<string> */
    private function renameLegacyRecords(Household $household): array
    {
        $names = DemoInventoryCatalog::legacyNames();
        $this->renameRecords($household->items()->withTrashed(), [
            'Demo Toilet Paper' => $names['Demo Toilet Paper'],
            'Demo Dish Soap' => $names['Demo Dish Soap'],
        ]);
        $this->renameRecords($household->categories()->withTrashed(), [
            'Demo Paper Goods' => $names['Demo Paper Goods'],
        ]);

        return $this->renameRecords($household->locations()->withTrashed(), [
            'Demo Basement' => $names['Demo Basement'],
            'Demo Main Pantry' => $names['Demo Main Pantry'],
            'Demo Bathroom 1' => $names['Demo Bathroom 1'],
            'Demo Bathroom 2' => $names['Demo Bathroom 2'],
            'Demo Bathroom 3' => $names['Demo Bathroom 3'],
        ]);
    }

    /** @param HasMany<Model, Household> $records @param array<string, string> $names @return list<string> */
    private function renameRecords(HasMany $records, array $names): array
    {
        $renamed = [];

        foreach ($names as $legacyName => $canonicalName) {
            $legacy = (clone $records)->where('name', $legacyName)->first();

            if ($legacy !== null && ! (clone $records)->where('name', $canonicalName)->exists()) {
                $legacy->forceFill(['name' => $canonicalName])->save();
                $renamed[] = $canonicalName;
            }
        }

        return $renamed;
    }

    /** @return Collection<string, ?Category> */
    private function seedCategories(Household $household): Collection
    {
        return collect(DemoInventoryCatalog::categories())->mapWithKeys(function (string $name) use ($household): array {
            $category = $household->categories()->withTrashed()->where('name', $name)->first();

            return [$name => $category ?? $household->categories()->create(['name' => $name])];
        });
    }

    /** @param list<string> $migratedLocationNames @return Collection<string, ?Location> */
    private function seedLocations(Household $household, array $migratedLocationNames): Collection
    {
        $locations = collect();

        foreach (DemoInventoryCatalog::locations() as $definition) {
            $location = $household->locations()->withTrashed()->where('name', $definition['name'])->first();
            if ($location === null && $definition['parent'] !== null && $locations->get($definition['parent']) === null) {
                $locations->put($definition['name'], null);

                continue;
            }

            $locations->put($definition['name'], $location ?? $household->locations()->create(['name' => $definition['name']]));
        }

        foreach (DemoInventoryCatalog::locations() as $definition) {
            /** @var Location $location */
            $location = $locations->get($definition['name']);
            if ($location === null || $location->trashed()) {
                continue;
            }
            /** @var ?Location $parent */
            $parent = $definition['parent'] === null ? null : $locations->get($definition['parent']);

            if (($location->wasRecentlyCreated || in_array($location->name, $migratedLocationNames, true))
                && $location->parent_id !== $parent?->getKey()) {
                $location->forceFill(['parent_id' => $parent?->getKey()])->save();
            }
        }

        return $locations;
    }

    /** @param Collection<string, ?Category> $categories @param Collection<string, ?Location> $locations */
    private function seedItems(
        Household $household,
        User $user,
        Collection $categories,
        Collection $locations,
        CreateInventoryMovement $movements,
    ): void {
        foreach (DemoInventoryCatalog::items() as $itemIndex => $definition) {
            $item = $household->items()->withTrashed()->where('name', $definition['name'])->first();
            if ($item === null) {
                $item = $household->items()->create([
                    'name' => $definition['name'],
                    'counting_unit' => $definition['unit'],
                ]);
            }

            if ($item->trashed()) {
                continue;
            }
            if ($item->wasRecentlyCreated) {
                $item->categories()->syncWithoutDetaching(
                    collect($definition['categories'])
                        ->map(fn (string $name): ?Category => $categories->get($name))
                        ->filter(fn (?Category $category): bool => $category !== null && ! $category->trashed())
                        ->map(fn (Category $category): int => $category->getKey())
                        ->all(),
                );
            }

            if ($item->wasRecentlyCreated && ! $item->inventoryLevels()->exists()) {
                $this->seedItemStock($household, $user, $item, $definition['locations'], $locations, $itemIndex, $movements);
            }
        }
    }

    /** @param list<string> $locationNames @param Collection<string, ?Location> $locations */
    private function seedItemStock(
        Household $household,
        User $user,
        Item $item,
        array $locationNames,
        Collection $locations,
        int $itemIndex,
        CreateInventoryMovement $movements,
    ): void {
        $activeLocations = collect($locationNames)
            ->map(fn (string $name): ?Location => $locations->get($name))
            ->filter(fn (?Location $location): bool => $location !== null && ! $location->trashed())
            ->values();

        foreach ($activeLocations as $locationIndex => $location) {
            /** @var Location $location */
            $quantity = 3 + (($itemIndex * 5 + $locationIndex * 3) % 18);
            $movements->create($household, $user, [
                'movement_type' => 'restock',
                'item_id' => $item->getKey(),
                'location_id' => $location->getKey(),
                'quantity' => $quantity,
            ]);

            if ($itemIndex % 10 === 0 && $locationIndex === 0) {
                $movements->create($household, $user, [
                    'movement_type' => 'correction',
                    'item_id' => $item->getKey(),
                    'location_id' => $location->getKey(),
                    'observed_quantity' => 0,
                ]);
            } elseif ($itemIndex % 6 === 0 && $locationIndex === 0) {
                $movements->create($household, $user, [
                    'movement_type' => 'consumption',
                    'item_id' => $item->getKey(),
                    'location_id' => $location->getKey(),
                    'quantity' => 1 + ($quantity % 3),
                ]);
            }
        }

        if ($activeLocations->count() > 1 && $itemIndex % 8 === 0 && $itemIndex % 10 !== 0) {
            /** @var Location $source */
            $source = $activeLocations->first();
            /** @var Location $destination */
            $destination = $activeLocations->get(1);
            $movements->create($household, $user, [
                'movement_type' => 'transfer',
                'item_id' => $item->getKey(),
                'source_location_id' => $source->getKey(),
                'destination_location_id' => $destination->getKey(),
                'quantity' => 1,
            ]);
        }

        $item->inventoryLevels()->get()->each(function (InventoryLevel $level) use ($itemIndex): void {
            $threshold = match (true) {
                $itemIndex % 10 === 0 => 2,
                $itemIndex % 7 === 0 => $level->quantity,
                $itemIndex % 4 === 0 => max(1, $level->quantity - 1),
                default => null,
            };
            $level->forceFill(['alert_threshold' => $threshold])->save();
        });
    }

    private function seedAlerts(Household $household, User $user, CreateInventoryAlert $alerts, ResolveInventoryAlert $resolveAlerts): void
    {
        foreach (['Dish Soap', 'Bandages', 'Trash Bags', 'Paper Towels', 'AA Batteries'] as $itemName) {
            $item = $household->items()->where('name', $itemName)->first();
            if ($item !== null && ! $item->inventoryAlerts()->exists()) {
                $alerts->create($household, $user, [
                    'item_id' => $item->getKey(),
                    'alert_type' => InventoryAlertType::BuySoon->value,
                ]);
            }
        }

        $resolvedItem = $household->items()->where('name', 'Sunscreen')->first();
        if ($resolvedItem === null || $resolvedItem->inventoryAlerts()->exists()) {
            return;
        }

        $alert = $alerts->create($household, $user, [
            'item_id' => $resolvedItem->getKey(),
            'alert_type' => InventoryAlertType::BuySoon->value,
        ]);
        $resolveAlerts->resolve($household, $alert, $user);
    }

    private function householdForDemoUser(User $user): Household
    {
        $memberships = $user->memberships()->withTrashed()->get();
        if ($memberships->isNotEmpty()) {
            $membership = $memberships->firstWhere('deleted_at', null);
            if ($membership === null) {
                throw new LogicException('The demo user has membership history but no active household.');
            }

            $household = Household::withTrashed()->find($membership->household_id);
            if ($household === null || $household->trashed()) {
                throw new ModelNotFoundException('The demo user’s active household is unavailable.');
            }

            return $household;
        }

        $household = Household::query()->create(['name' => 'Inventory Demo Household']);
        Membership::query()->create([
            'household_id' => $household->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return $household;
    }
}
