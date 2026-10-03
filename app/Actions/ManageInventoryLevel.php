<?php

namespace App\Actions;

use App\Models\Household;
use App\Models\InventoryLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageInventoryLevel
{
    /** @param array{item_id: int|string, location_id: int|string, alert_threshold?: int|string|null} $data */
    public function create(Household $household, array $data): InventoryLevel
    {
        return DB::transaction(function () use ($household, $data): InventoryLevel {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
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

            return $level->load(['item', 'location']);
        });
    }

    /** @param array{alert_threshold?: int|string|null} $data */
    public function update(Household $household, InventoryLevel $inventoryLevel, array $data): InventoryLevel
    {
        return DB::transaction(function () use ($household, $inventoryLevel, $data): InventoryLevel {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
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
