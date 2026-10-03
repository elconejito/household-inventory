<?php

namespace App\Models;

use Database\Factories\ItemImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable([
    'item_id', 'disk', 'thumbnail_path', 'thumbnail_mime_type', 'thumbnail_width', 'thumbnail_height',
    'display_path', 'display_mime_type', 'display_width', 'display_height', 'caption', 'is_primary',
    'uploaded_by', 'uploaded_at',
])]
class ItemImage extends Model
{
    /** @use HasFactory<ItemImageFactory> */
    use HasFactory, SoftDeletes;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'uploaded_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::forceDeleted(function (ItemImage $itemImage): void {
            try {
                Storage::disk($itemImage->disk)->delete([
                    $itemImage->thumbnail_path,
                    $itemImage->display_path,
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
