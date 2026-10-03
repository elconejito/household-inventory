<?php

namespace App\Transformers;

use App\Models\Item;
use Illuminate\Support\Str;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class ItemTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['categories', 'inventory_levels'];

    /**
     * @return array{type: string, id: string, name: string, counting_unit: string, counting_unit_plural: string, description: string|null, total_quantity: int}
     */
    public function transform(mixed $item): array
    {
        /** @var Item $item */
        return [
            'type' => 'items',
            'id' => (string) $item->getKey(),
            'name' => $item->name,
            'counting_unit' => $item->counting_unit,
            'counting_unit_plural' => Str::plural($item->counting_unit),
            'description' => $item->description,
            'total_quantity' => (int) ($item->total_quantity ?? 0),
        ];
    }

    public function includeCategories(Item $item): ResourceInterface
    {
        return $this->collection($item->categories, new CategoryTransformer);
    }

    public function includeInventoryLevels(Item $item): ResourceInterface
    {
        return $this->collection($item->inventoryLevels, new InventoryLevelTransformer);
    }
}
