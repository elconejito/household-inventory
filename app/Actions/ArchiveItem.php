<?php

namespace App\Actions;

use App\Exceptions\InventoryArchiveBlocked;
use App\Models\Household;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class ArchiveItem
{
    public function archive(Household $household, Item $item): void
    {
        DB::transaction(function () use ($household, $item): void {
            Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $item = $household->items()->lockForUpdate()->findOrFail($item->getKey());

            $blockers = [];
            if ($item->inventoryLevels()->where('quantity', '>', 0)->exists()) {
                $blockers[] = 'positive inventory';
            }
            if ($item->inventoryAlerts()->whereNull('resolved_at')->exists()) {
                $blockers[] = 'an unresolved manual alert';
            }
            if ($blockers !== []) {
                throw new InventoryArchiveBlocked($blockers);
            }

            $item->delete();
        });
    }
}
