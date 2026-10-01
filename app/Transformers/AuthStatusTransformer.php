<?php

namespace App\Transformers;

use League\Fractal\TransformerAbstract;

class AuthStatusTransformer extends TransformerAbstract
{
    /**
     * @param  array{status: string}  $status
     * @return array{type: string, id: string, status: string}
     */
    public function transform(mixed $status): array
    {
        return [
            'type' => 'sessions',
            'id' => 'current',
            'status' => $status['status'],
        ];
    }
}
