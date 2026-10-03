<?php

namespace Database\Seeders;

use App\Actions\CreateInventoryAlert;
use App\Actions\CreateInventoryMovement;
use App\Enums\InventoryAlertType;
use App\Models\Household;
use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class InventoryStockSeeder extends Seeder
{
    public function run(CreateInventoryMovement $movements, CreateInventoryAlert $alerts): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Inventory demo data can only be seeded in local or testing environments.');
        }

        DB::transaction(function () use ($movements, $alerts): void {
            $user = User::query()->firstOrCreate(
                ['email' => 'inventory-demo@example.test'],
                ['name' => 'Inventory Demo', 'password' => 'password'],
            );
            $household = $this->householdForDemoUser($user);

            if (Item::withTrashed()
                ->where('household_id', $household->getKey())
                ->where('name', 'Demo Toilet Paper')
                ->exists()) {
                return;
            }

            $basement = $household->locations()->firstOrCreate(
                ['parent_id' => null, 'name' => 'Demo Basement'],
            );
            $pantry = $household->locations()->firstOrCreate(
                ['parent_id' => null, 'name' => 'Demo Main Pantry'],
            );
            $bathrooms = collect(['Demo Bathroom 1', 'Demo Bathroom 2', 'Demo Bathroom 3'])
                ->map(fn (string $name): Location => $household->locations()->firstOrCreate(
                    ['parent_id' => null, 'name' => $name],
                ));
            $paper = $household->items()->create([
                'name' => 'Demo Toilet Paper',
                'counting_unit' => 'roll',
            ]);
            $category = $household->categories()->firstOrCreate(['name' => 'Demo Paper Goods']);
            $paper->categories()->syncWithoutDetaching([$category->getKey()]);

            $movements->create($household, $user, [
                'movement_type' => 'restock',
                'item_id' => $paper->getKey(),
                'location_id' => $basement->getKey(),
                'quantity' => 24,
            ]);
            InventoryLevel::query()
                ->where('item_id', $paper->getKey())
                ->where('location_id', $basement->getKey())
                ->update(['alert_threshold' => 12]);

            $movements->create($household, $user, [
                'movement_type' => 'transfer',
                'item_id' => $paper->getKey(),
                'source_location_id' => $basement->getKey(),
                'destination_location_id' => $pantry->getKey(),
                'quantity' => 12,
            ]);

            foreach ($bathrooms as $bathroom) {
                $movements->create($household, $user, [
                    'movement_type' => 'transfer',
                    'item_id' => $paper->getKey(),
                    'source_location_id' => $pantry->getKey(),
                    'destination_location_id' => $bathroom->getKey(),
                    'quantity' => 2,
                ]);
            }

            $movements->create($household, $user, [
                'movement_type' => 'consumption',
                'item_id' => $paper->getKey(),
                'location_id' => $bathrooms->first()->getKey(),
                'quantity' => 1,
            ]);

            $soap = $household->items()->create([
                'name' => 'Demo Dish Soap',
                'counting_unit' => 'bottle',
            ]);

            $alerts->create($household, $user, [
                'item_id' => $soap->getKey(),
                'alert_type' => InventoryAlertType::BuySoon->value,
            ]);
        });
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
