<?php

namespace App\Actions;

use App\Actions\Concerns\RequiresActiveHouseholdMembership;
use App\Enums\InventoryAlertType;
use App\Exceptions\ActiveInventoryAlertExists;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateInventoryAlert
{
    use RequiresActiveHouseholdMembership;

    /** @param array{item_id: int|string, alert_type: string} $data */
    public function create(Household $household, User $creator, array $data): InventoryAlert
    {
        return DB::transaction(function () use ($household, $creator, $data): InventoryAlert {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $creator);
            $item = $household->items()->lockForUpdate()->findOrFail($data['item_id']);

            $activeAlert = $item->inventoryAlerts()
                ->where('alert_type', InventoryAlertType::from($data['alert_type'])->value)
                ->whereNull('resolved_at')
                ->lockForUpdate()
                ->first();

            if ($activeAlert !== null) {
                throw new ActiveInventoryAlertExists;
            }

            return InventoryAlert::query()->create([
                'household_id' => $household->getKey(),
                'item_id' => $item->getKey(),
                'alert_type' => InventoryAlertType::from($data['alert_type']),
                'created_by' => $creator->getKey(),
                'created_at' => now(),
            ]);
        });
    }
}
