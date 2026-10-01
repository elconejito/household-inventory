<?php

namespace App\Transformers;

use App\Models\Item;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class ItemTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['categories'];

    /**
     * @return array{type: string, id: string, name: string, counting_unit: string, description: string|null}
     */
    public function transform(mixed $item): array
    {
        /** @var Item $item */
        return [
            'type' => 'items',
            'id' => (string) $item->getKey(),
            'name' => $item->name,
            'counting_unit' => $item->counting_unit,
            'description' => $item->description,
        ];
    }

    public function includeCategories(Item $item): ResourceInterface
    {
        return $this->collection($item->categories, new CategoryTransformer);
    }
}
