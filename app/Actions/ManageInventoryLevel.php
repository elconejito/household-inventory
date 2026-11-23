<?php

namespace App\Actions;

use App\Actions\Concerns\RequiresActiveHouseholdMembership;
use App\Models\Household;
use App\Models\InventoryLevel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageInventoryLevel
{
    use RequiresActiveHouseholdMembership;

    public function __construct(private CreateInventoryMovement $movements) {}

    /** @param array{item_id: int|string, location_id: int|string, alert_threshold?: int|string|null, opening_quantity?: int|string} $data */
    public function create(Household $household, User $actor, array $data): InventoryLevel
    {
        return DB::transaction(function () use ($household, $actor, $data): InventoryLevel {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $actor);
            $item = $household->items()->lockForUpdate()->find($data['item_id']);
            $location = $household->locations()->whereKey($data['location_id'])->lockForUpdate()->first();
            if ($item === null) {
                $this->fail('data.item_id', 'The selected item is invalid.');
            }
            if ($location === null) {
                $this->fail('data.location_id', 'The selected location is invalid.');
            }

            if (InventoryLevel::query()->where('item_id', $item->getKey())->where('location_id', $location->getKey())->exists()) {
                abort(409);
            }

            $level = new InventoryLevel([
                'item_id' => $item->getKey(),
                'location_id' => $location->getKey(),
                'alert_threshold' => $data['alert_threshold'] ?? null,
            ]);
            $level->quantity = 0;
            $level->save();

            if ((int) ($data['opening_quantity'] ?? 0) > 0) {
                $this->movements->create($household, $actor, [
                    'movement_type' => 'restock',
                    'item_id' => $item->getKey(),
                    'location_id' => $location->getKey(),
                    'quantity' => (int) $data['opening_quantity'],
                ]);
                $level->refresh();
            }

            return $level->load(['item', 'location']);
        });
    }

    /** @return array{level: InventoryLevel, previous_quantity: int} */
    public function count(Household $household, User $actor, string $locationId, string $itemId, int $quantity): array
    {
        return DB::transaction(function () use ($household, $actor, $locationId, $itemId, $quantity): array {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $actor);
            $item = $household->items()->whereKey($itemId)->firstOrFail();
            $location = $household->locations()->whereKey($locationId)->firstOrFail();
            $level = $item->inventoryLevels()->where('location_id', $location->getKey())->lockForUpdate()->first();
            if ($level === null) {
                $created = $this->create($household, $actor, [
                    'item_id' => $item->getKey(),
                    'location_id' => $location->getKey(),
                    'opening_quantity' => $quantity,
                ]);

                return ['level' => $created, 'previous_quantity' => 0];
            }
            $previousQuantity = $level->quantity;
            if ($level->quantity !== $quantity) {
                $this->movements->create($household, $actor, [
                    'movement_type' => 'correction',
                    'item_id' => $item->getKey(),
                    'location_id' => $location->getKey(),
                    'observed_quantity' => $quantity,
                ]);
                $level->refresh();
            }

            return ['level' => $level, 'previous_quantity' => $previousQuantity];
        });
    }

    /** @param array{alert_threshold?: int|string|null} $data */
    public function update(Household $household, InventoryLevel $inventoryLevel, User $actor, array $data): InventoryLevel
    {
        return DB::transaction(function () use ($household, $inventoryLevel, $actor, $data): InventoryLevel {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $actor);
            $item = $household->items()->lockForUpdate()->find($inventoryLevel->item_id);
            $location = $household->locations()->whereKey($inventoryLevel->location_id)->lockForUpdate()->first();
            if ($item === null) {
                $this->fail('inventory_level', 'The inventory level was not found.');
            }
            if ($location === null) {
                $this->fail('inventory_level', 'The inventory level was not found.');
            }
            $level = InventoryLevel::query()
                ->whereKey($inventoryLevel->getKey())
                ->where('item_id', $item->getKey())
                ->where('location_id', $location->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if (array_key_exists('alert_threshold', $data)) {
                $level->alert_threshold = $data['alert_threshold'];
            }
            $level->save();

            return $level->load(['item', 'location']);
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
