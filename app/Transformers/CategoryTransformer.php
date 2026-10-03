<?php

namespace App\Transformers;

use App\Models\Category;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class CategoryTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['items', 'notes'];

    /**
     * @return array{type: string, id: string, name: string}
     */
    public function transform(mixed $category): array
    {
        /** @var Category $category */
        return [
            'type' => 'categories',
            'id' => (string) $category->getKey(),
            'name' => $category->name,
        ];
    }

    public function includeItems(Category $category): ResourceInterface
    {
        return $this->collection($category->items, new ItemTransformer);
    }

    public function includeNotes(Category $category): ResourceInterface
    {
        return $this->collection($category->notes, new NoteTransformer);
    }
}
