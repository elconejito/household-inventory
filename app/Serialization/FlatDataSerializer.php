<?php

namespace App\Serialization;

use League\Fractal\Serializer\ArraySerializer;

class FlatDataSerializer extends ArraySerializer
{
    public function collection(?string $resourceKey, array $data): array
    {
        return $resourceKey === 'data' ? ['data' => $data] : $data;
    }

    public function item(?string $resourceKey, array $data): array
    {
        return $resourceKey === 'data' ? ['data' => $data] : $data;
    }
}
