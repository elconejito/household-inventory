<?php

namespace App\Models;

use App\Enums\MovementType;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['household_id', 'item_id', 'movement_type', 'recorded_by', 'recorded_at'])]
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['movement_type' => MovementType::class, 'recorded_at' => 'immutable_datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed()->withInventorySummary();
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(InventoryMovementEntry::class);
    }
}
