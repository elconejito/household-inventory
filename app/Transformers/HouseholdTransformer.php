<?php

namespace App\Transformers;

use App\Models\Household;
use League\Fractal\TransformerAbstract;

class HouseholdTransformer extends TransformerAbstract
{
    /**
     * @param  Household  $household
     * @return array{type: string, id: string, name: string}
     */
    public function transform(mixed $household): array
    {
        return [
            'type' => 'households',
            'id' => (string) $household->getKey(),
            'name' => $household->name,
        ];
    }
}
