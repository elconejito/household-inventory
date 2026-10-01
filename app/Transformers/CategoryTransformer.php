<?php

namespace App\Transformers;

use App\Models\Category;
use League\Fractal\TransformerAbstract;

class CategoryTransformer extends TransformerAbstract
{
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
}
