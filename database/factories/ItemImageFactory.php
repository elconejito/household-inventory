<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemImage>
 */
class ItemImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'disk' => 'inventory-images',
            'thumbnail_path' => 'items/test/thumbnail.webp',
            'thumbnail_mime_type' => 'image/webp',
            'thumbnail_width' => 320,
            'thumbnail_height' => 240,
            'display_path' => 'items/test/display.webp',
            'display_mime_type' => 'image/webp',
            'display_width' => 1280,
            'display_height' => 960,
            'caption' => fake()->optional()->sentence(),
            'is_primary' => false,
            'uploaded_by' => null,
            'uploaded_at' => now(),
        ];
    }
}
