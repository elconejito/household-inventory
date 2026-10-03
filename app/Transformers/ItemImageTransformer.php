<?php

namespace App\Transformers;

use App\Models\ItemImage;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\TransformerAbstract;

class ItemImageTransformer extends TransformerAbstract
{
    protected array $availableIncludes = ['uploaded_by'];

    /**
     * @return array<string, mixed>
     */
    public function transform(mixed $itemImage): array
    {
        /** @var ItemImage $itemImage */
        return [
            'type' => 'item-images',
            'id' => (string) $itemImage->getKey(),
            'caption' => $itemImage->caption,
            'is_primary' => $itemImage->is_primary,
            'uploaded_at' => $itemImage->uploaded_at?->toISOString(),
            'thumbnail_url' => route('item-images.thumbnail', ['item_image' => $itemImage->getKey()]),
            'thumbnail_mime_type' => $itemImage->thumbnail_mime_type,
            'thumbnail_width' => $itemImage->thumbnail_width,
            'thumbnail_height' => $itemImage->thumbnail_height,
            'display_url' => route('item-images.display', ['item_image' => $itemImage->getKey()]),
            'display_mime_type' => $itemImage->display_mime_type,
            'display_width' => $itemImage->display_width,
            'display_height' => $itemImage->display_height,
        ];
    }

    public function includeUploadedBy(ItemImage $itemImage): ResourceInterface
    {
        return $itemImage->uploader === null
            ? $this->null()
            : $this->item($itemImage->uploader, new UserTransformer);
    }
}
