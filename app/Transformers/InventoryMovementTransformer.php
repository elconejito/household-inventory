<?php

namespace App\Transformers;

use App\Models\InventoryMovement;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class InventoryMovementTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['item', 'entries', 'recorded_by', 'notes'];

    /** @return array{type: string, id: string, movement_type: string, recorded_at: string} */
    public function transform(mixed $movement): array
    {
        /** @var InventoryMovement $movement */
        return [
            'type' => 'inventory-movements',
            'id' => (string) $movement->getKey(),
            'movement_type' => $movement->movement_type->value,
            'recorded_at' => $movement->recorded_at->utc()->toISOString(),
        ];
    }

    public function includeItem(InventoryMovement $movement): ResourceInterface
    {
        return $this->item($movement->item, new ItemTransformer);
    }

    public function includeEntries(InventoryMovement $movement): ResourceInterface
    {
        return $this->collection($movement->entries, new InventoryMovementEntryTransformer);
    }

    public function includeRecordedBy(InventoryMovement $movement): ResourceInterface
    {
        return $this->item($movement->recorder, new UserTransformer);
    }

    public function includeNotes(InventoryMovement $movement): ResourceInterface
    {
        return $this->collection($movement->notes, new NoteTransformer);
    }
}
