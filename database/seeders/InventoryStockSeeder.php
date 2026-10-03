<?php

namespace Database\Seeders;

use App\Actions\CreateInventoryMovement;
use App\Models\Household;
use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Seeder;

class InventoryStockSeeder extends Seeder
{
    public function run(CreateInventoryMovement $movements): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'inventory-demo@example.test'],
            ['name' => 'Inventory Demo', 'password' => 'password'],
        );
        $household = Household::query()->firstOrCreate(['name' => 'Inventory Demo Household']);
        Membership::query()->firstOrCreate(
            ['household_id' => $household->getKey(), 'user_id' => $user->getKey()],
            ['role' => 'owner', 'joined_at' => now()],
        );
        $basement = Location::query()->firstOrCreate(
            ['household_id' => $household->getKey(), 'parent_id' => null, 'name' => 'Demo Basement'],
        );
        $shelf = Location::query()->firstOrCreate(
            ['household_id' => $household->getKey(), 'parent_id' => $basement->getKey(), 'name' => 'Demo Shelf'],
        );
        $paper = Item::query()->firstOrCreate(
            ['household_id' => $household->getKey(), 'name' => 'Demo Paper Towels'],
            ['counting_unit' => 'roll'],
        );
        $batteries = Item::query()->firstOrCreate(
            ['household_id' => $household->getKey(), 'name' => 'Demo Batteries'],
            ['counting_unit' => 'piece'],
        );

        $this->restockIfEmpty($movements, $household, $user, $paper, $basement, 24);
        $this->restockIfEmpty($movements, $household, $user, $paper, $shelf, 6);
        $this->restockIfEmpty($movements, $household, $user, $batteries, $shelf, 8);
    }

    private function restockIfEmpty(CreateInventoryMovement $movements, Household $household, User $user, Item $item, Location $location, int $quantity): void
    {
        $level = InventoryLevel::query()->where('item_id', $item->getKey())->where('location_id', $location->getKey())->first();
        if ($level !== null && $level->quantity > 0) {
            return;
        }

        $movements->create($household, $user, [
            'movement_type' => 'restock',
            'item_id' => $item->getKey(),
            'location_id' => $location->getKey(),
            'quantity' => $quantity,
        ]);
    }
}
