<?php

namespace App\Models;

use Database\Factories\ItemImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
