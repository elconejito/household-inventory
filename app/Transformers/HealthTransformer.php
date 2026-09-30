<?php

namespace App\Transformers;

use League\Fractal\TransformerAbstract;

class HealthTransformer extends TransformerAbstract
{
    /**
     * @param  array{status: string}  $health
     * @return array{type: string, id: string, status: string}
     */
    public function transform(mixed $health): array
    {
        return [
            'type' => 'health',
            'id' => 'api',
            'status' => $health['status'],
        ];
    }
}
