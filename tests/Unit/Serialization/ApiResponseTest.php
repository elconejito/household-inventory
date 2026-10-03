<?php

namespace Tests\Unit\Serialization;

use App\Serialization\ApiResponse;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;
use PHPUnit\Framework\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_requested_absent_single_relationships_are_null_and_empty_collections_are_arrays(): void
    {
        $transformer = new class extends TransformerAbstract
        {
            protected array $availableIncludes = ['resolver', 'notes'];

            public function transform(mixed $alert): array
            {
                return ['type' => 'inventory-alerts', 'id' => '7'];
            }

            public function includeResolver(mixed $alert): ResourceInterface
            {
                return $this->null();
            }

            public function includeNotes(mixed $alert): Collection
            {
                return $this->collection([], new self);
            }
        };

        $response = (new ApiResponse)->item([], $transformer, ['resolver', 'notes']);

        $this->assertSame([
            'data' => ['type' => 'inventory-alerts', 'id' => '7', 'resolver' => null, 'notes' => []],
        ], $response);
    }

    public function test_omits_available_relationships_until_they_are_explicitly_requested(): void
    {
        $transformer = $this->itemTransformer();

        $response = (new ApiResponse)->item([
            'id' => 42,
            'name' => 'Toilet paper',
            'categories' => [['id' => 3, 'name' => 'Paper goods']],
        ], $transformer);

        $this->assertSame([
            'data' => [
                'type' => 'items',
                'id' => '42',
                'name' => 'Toilet paper',
            ],
        ], $response);
    }

    public function test_wraps_a_single_item_and_explicit_relationships_in_one_flat_data_envelope(): void
    {
        $transformer = $this->itemTransformer();

        $response = (new ApiResponse)->item([
            'id' => 42,
            'name' => 'Toilet paper',
            'categories' => [
                ['id' => 3, 'name' => 'Paper goods'],
            ],
        ], $transformer, ['categories']);

        $this->assertSame([
            'data' => [
                'type' => 'items',
                'id' => '42',
                'name' => 'Toilet paper',
                'categories' => [
                    [
                        'type' => 'categories',
                        'id' => '3',
                        'name' => 'Paper goods',
                    ],
                ],
            ],
        ], $response);
    }

    public function test_wraps_a_collection_and_each_explicit_relationship_without_nested_data_keys(): void
    {
        $transformer = $this->itemTransformer();

        $response = (new ApiResponse)->collection([
            [
                'id' => 42,
                'name' => 'Toilet paper',
                'categories' => [['id' => 3, 'name' => 'Paper goods']],
            ],
            [
                'id' => 43,
                'name' => 'Paper towels',
                'categories' => [['id' => 3, 'name' => 'Paper goods']],
            ],
        ], $transformer, ['categories']);

        $this->assertSame([
            'data' => [
                [
                    'type' => 'items',
                    'id' => '42',
                    'name' => 'Toilet paper',
                    'categories' => [['type' => 'categories', 'id' => '3', 'name' => 'Paper goods']],
                ],
                [
                    'type' => 'items',
                    'id' => '43',
                    'name' => 'Paper towels',
                    'categories' => [['type' => 'categories', 'id' => '3', 'name' => 'Paper goods']],
                ],
            ],
        ], $response);
    }

    private function itemTransformer(): TransformerAbstract
    {
        return new class extends TransformerAbstract
        {
            protected array $availableIncludes = ['categories'];

            public function transform(mixed $item): array
            {
                return [
                    'type' => 'items',
                    'id' => (string) $item['id'],
                    'name' => $item['name'],
                ];
            }

            public function includeCategories(mixed $item): Collection
            {
                $transformer = new class extends TransformerAbstract
                {
                    public function transform(mixed $category): array
                    {
                        return [
                            'type' => 'categories',
                            'id' => (string) $category['id'],
                            'name' => $category['name'],
                        ];
                    }
                };

                return $this->collection($item['categories'], $transformer);
            }
        };
    }
}
