<?php

namespace App\Transformers;

use App\Models\InventoryAlert;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class InventoryAlertTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['item', 'created_by', 'resolved_by'];

    /** @return array{type: string, id: string, alert_type: string, created_at: string, resolved_at: string|null} */
    public function transform(mixed $alert): array
    {
        /** @var InventoryAlert $alert */
        return [
            'type' => 'inventory-alerts',
            'id' => (string) $alert->getKey(),
            'alert_type' => $alert->alert_type->value,
            'created_at' => $alert->created_at->toISOString(),
            'resolved_at' => $alert->resolved_at?->toISOString(),
        ];
    }

    public function includeItem(InventoryAlert $alert): ResourceInterface
    {
        return $this->item($alert->item, new ItemTransformer);
    }

    public function includeCreatedBy(InventoryAlert $alert): ResourceInterface
    {
        return $this->item($alert->creator, new UserTransformer);
    }

    public function includeResolvedBy(InventoryAlert $alert): ResourceInterface
    {
        return $alert->resolver === null
            ? $this->null()
            : $this->item($alert->resolver, new UserTransformer);
    }
}
