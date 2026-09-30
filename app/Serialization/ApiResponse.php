<?php

namespace App\Serialization;

use Illuminate\Support\Collection;
use League\Fractal\Manager;
use League\Fractal\Resource\Collection as FractalCollection;
use League\Fractal\Resource\Item;
use League\Fractal\TransformerAbstract;

class ApiResponse
{
    /**
     * @param  array<int, string>  $includes
     * @return array<string, mixed>
     */
    public function item(mixed $resource, TransformerAbstract $transformer, array $includes = []): array
    {
        return $this->manager($includes)
            ->createData(new Item($resource, $transformer, 'data'))
            ->toArray();
    }

    /**
     * @param  iterable<mixed>  $resources
     * @param  array<int, string>  $includes
     * @return array<string, mixed>
     */
    public function collection(iterable $resources, TransformerAbstract $transformer, array $includes = []): array
    {
        if ($resources instanceof Collection) {
            $resources = $resources->all();
        }

        return $this->manager($includes)
            ->createData(new FractalCollection($resources, $transformer, 'data'))
            ->toArray();
    }

    /**
     * @param  array<int, string>  $includes
     */
    private function manager(array $includes): Manager
    {
        $manager = new Manager;
        $manager->setSerializer(new FlatDataSerializer);

        if ($includes !== []) {
            $manager->parseIncludes($includes);
        }

        return $manager;
    }
}
