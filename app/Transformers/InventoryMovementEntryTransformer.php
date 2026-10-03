<?php

namespace App\Transformers;

use App\Models\InventoryMovementEntry;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class InventoryMovementEntryTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['location'];

    /** @return array{type: string, id: string, quantity_delta: int, balance_after: int} */
    public function transform(mixed $entry): array
    {
        /** @var InventoryMovementEntry $entry */
        return [
            'type' => 'inventory-movement-entries',
            'id' => (string) $entry->getKey(),
            'quantity_delta' => (int) $entry->quantity_delta,
            'balance_after' => (int) $entry->balance_after,
        ];
    }

    public function includeLocation(InventoryMovementEntry $entry): ResourceInterface
    {
        return $this->item($entry->location, new LocationTransformer);
    }
}
