<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['name', 'counting_unit', 'description'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, SoftDeletes;

    public $timestamps = false;

    protected function name(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => Str::squish($value),
        );
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_item');
    }
}
