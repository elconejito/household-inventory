<?php

namespace App\Actions;

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

            abort_if($item->inventoryLevels()->where('quantity', '>', 0)->exists(), 409);

            $item->delete();
        });
    }
}
