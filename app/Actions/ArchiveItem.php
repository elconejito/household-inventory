<?php

namespace App\Actions;

use App\Actions\Concerns\RequiresActiveHouseholdMembership;
use App\Exceptions\InventoryArchiveBlocked;
use App\Models\Household;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveItem
{
    use RequiresActiveHouseholdMembership;

    public function archive(Household $household, Item $item, User $actor): void
    {
        DB::transaction(function () use ($household, $item, $actor): void {
            $household = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertActiveMembership($household, $actor);
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
