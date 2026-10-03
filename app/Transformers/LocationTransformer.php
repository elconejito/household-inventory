<?php

namespace App\Transformers;

use App\Models\Location;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class LocationTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['parent', 'children', 'notes'];

    /**
     * @return array{type: string, id: string, name: string, description: string|null}
     */
    public function transform(mixed $location): array
    {
        /** @var Location $location */
        return [
            'type' => 'locations',
            'id' => (string) $location->getKey(),
            'name' => $location->name,
            'description' => $location->description,
        ];
    }

    public function includeParent(Location $location): ResourceInterface
    {
        $parent = $location->parent;

        if ($parent === null || $parent->household_id !== $location->household_id) {
            return $this->null();
        }

        return $this->item($parent, new self);
    }

    public function includeChildren(Location $location): ResourceInterface
    {
        return $this->collection($location->children->sortBy('name')->values(), new self);
    }

    public function includeNotes(Location $location): ResourceInterface
    {
        return $this->collection($location->notes, new NoteTransformer);
    }
}
