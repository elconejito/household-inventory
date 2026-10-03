<?php

namespace App\Actions;

use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResolveInventoryAlert
{
    public function resolve(Household $household, InventoryAlert $alert, User $resolver): InventoryAlert
    {
        return DB::transaction(function () use ($household, $alert, $resolver): InventoryAlert {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $item = $household->items()->withTrashed()->lockForUpdate()->findOrFail($alert->item_id);
            $lockedAlert = $item->inventoryAlerts()->lockForUpdate()->findOrFail($alert->getKey());

            if ($lockedAlert->resolved_at === null) {
                $lockedAlert->forceFill([
                    'resolved_at' => now(),
                    'resolved_by' => $resolver->getKey(),
                ])->save();
            }

            return $lockedAlert;
        });
    }
}
