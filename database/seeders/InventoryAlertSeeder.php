<?php

namespace Database\Seeders;

use App\Actions\CreateInventoryAlert;
use App\Enums\InventoryAlertType;
use App\Exceptions\ActiveInventoryAlertExists;
use App\Models\Household;
use Illuminate\Database\Seeder;

class InventoryAlertSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $create = app(CreateInventoryAlert::class);
        $household = Household::query()->first();
        $item = $household?->items()->first();
        $user = $household?->users()->first();

        if ($household === null || $item === null || $user === null) {
            return;
        }

        try {
            $create->create($household, $user, [
                'item_id' => $item->getKey(),
                'alert_type' => InventoryAlertType::BuySoon->value,
            ]);
        } catch (ActiveInventoryAlertExists) {
            return;
        }
    }
}
