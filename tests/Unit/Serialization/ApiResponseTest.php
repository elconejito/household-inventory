<?php

namespace Tests\Unit\Serialization;

use App\Serialization\ApiResponse;
use League\Fractal\Resource\Collection;
use League\Fractal\TransformerAbstract;
use PHPUnit\Framework\TestCase;

class ApiResponseTest extends TestCase
{
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
