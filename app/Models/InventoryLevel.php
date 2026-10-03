<?php

namespace App\Models;

use Database\Factories\InventoryLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'location_id', 'alert_threshold'])]
class InventoryLevel extends Model
{
    /** @use HasFactory<InventoryLevelFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'alert_threshold' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withInventorySummary();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function stockStatus(): Attribute
    {
        return Attribute::get(fn (): string => $this->quantity > 0 ? 'in_stock' : 'empty');
    }

    protected function alertStatus(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->alert_threshold === null => 'unmonitored',
            $this->quantity === 0 => 'empty',
            $this->quantity <= $this->alert_threshold => 'low',
            default => 'okay',
        });
    }
}
