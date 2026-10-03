<?php

namespace App\Transformers;

use App\Models\InventoryLevel;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class InventoryLevelTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['item', 'location'];

    /** @return array{type: string, id: string, quantity: int, alert_threshold: int|null, stock_status: string, alert_status: string} */
    public function transform(mixed $level): array
    {
        /** @var InventoryLevel $level */
        return [
            'type' => 'inventory-levels',
            'id' => (string) $level->getKey(),
            'quantity' => (int) $level->quantity,
            'alert_threshold' => $level->alert_threshold === null ? null : (int) $level->alert_threshold,
            'stock_status' => $level->stock_status,
            'alert_status' => $level->alert_status,
        ];
    }

    public function includeItem(InventoryLevel $level): ResourceInterface
    {
        return $this->item($level->item, new ItemTransformer);
    }

    public function includeLocation(InventoryLevel $level): ResourceInterface
    {
        return $this->item($level->location, new LocationTransformer);
    }
}
