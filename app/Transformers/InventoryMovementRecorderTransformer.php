<?php

namespace App\Transformers;

use App\Models\User;
use League\Fractal\TransformerAbstract;

class InventoryMovementRecorderTransformer extends TransformerAbstract
{
    /** @return array{type: string, id: string, name: string} */
    public function transform(mixed $user): array
    {
        /** @var User $user */
        return [
            'type' => 'users',
            'id' => (string) $user->getKey(),
            'name' => $user->name,
        ];
    }
}
