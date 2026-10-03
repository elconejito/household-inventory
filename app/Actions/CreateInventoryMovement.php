<?php

namespace App\Actions;

use App\Enums\MovementType;
use App\Models\Household;
use App\Models\InventoryLevel;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInventoryMovement
{
    private const MAX_QUANTITY = 4294967295;

    /** @param array<string, int|string> $data */
    public function create(Household $household, User $recorder, array $data): InventoryMovement
    {
        return DB::transaction(function () use ($household, $recorder, $data): InventoryMovement {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $item = $household->items()->lockForUpdate()->find($data['item_id']);
            if ($item === null) {
                $this->fail('data.item_id', 'The selected item is invalid.');
            }

            $type = MovementType::from($data['movement_type']);
            $locationIds = match ($type) {
                MovementType::Transfer => [(int) $data['source_location_id'], (int) $data['destination_location_id']],
                default => [(int) $data['location_id']],
            };
            sort($locationIds);
            $locations = $household->locations()
                ->whereIn('id', $locationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($locations->count() !== count(array_unique($locationIds))) {
                $this->fail('data.location_id', 'The selected location is invalid.');
            }

            if ($type === MovementType::Transfer && $locationIds[0] === $locationIds[1]) {
                $this->fail('data.destination_location_id', 'Source and destination locations must differ.');
            }

            $levels = InventoryLevel::query()
                ->where('item_id', $item->getKey())
                ->whereIn('location_id', $locationIds)
                ->orderBy('location_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('location_id');

            $quantity = (int) ($data['quantity'] ?? 0);
            $deltas = [];
            $resultingBalances = [];
            foreach ($locationIds as $locationId) {
                $level = $levels->get($locationId);
                $balance = (int) ($level?->quantity ?? 0);
                $delta = match ($type) {
                    MovementType::Restock => $quantity,
                    MovementType::Transfer => $locationId === (int) $data['source_location_id'] ? -$quantity : $quantity,
                    MovementType::Consumption, MovementType::Disposal => -$quantity,
                    MovementType::Correction => (int) $data['observed_quantity'] - $balance,
                };
                $newBalance = $balance + $delta;

                if ($newBalance < 0) {
                    $this->fail('data.quantity', 'The movement cannot reduce inventory below zero.');
                }
                if ($newBalance > self::MAX_QUANTITY) {
                    $this->fail($type === MovementType::Correction ? 'data.observed_quantity' : 'data.quantity', 'The resulting quantity is too large.');
                }

                $deltas[$locationId] = $delta;
                $resultingBalances[$locationId] = $newBalance;
            }

            if ($type === MovementType::Correction && reset($deltas) === 0) {
                $this->fail('data.observed_quantity', 'The observed quantity matches the recorded quantity.');
            }

            $movement = InventoryMovement::query()->create([
                'household_id' => $household->getKey(),
                'item_id' => $item->getKey(),
                'movement_type' => $type,
                'recorded_by' => $recorder->getKey(),
                'recorded_at' => now(),
            ]);

            foreach ($locationIds as $locationId) {
                $level = $levels->get($locationId) ?? new InventoryLevel([
                    'item_id' => $item->getKey(),
                    'location_id' => $locationId,
                    'alert_threshold' => null,
                ]);
                $level->quantity = $resultingBalances[$locationId];
                $level->save();

                $movement->entries()->create([
                    'location_id' => $locationId,
                    'quantity_delta' => $deltas[$locationId],
                    'balance_after' => $resultingBalances[$locationId],
                ]);
            }

            return $movement->load(['item', 'entries.location', 'recorder']);
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
