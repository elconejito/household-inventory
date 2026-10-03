<?php

namespace App\Models;

use Database\Factories\InventoryMovementEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['inventory_movement_id', 'location_id', 'quantity_delta', 'balance_after'])]
class InventoryMovementEntry extends Model
{
    /** @use HasFactory<InventoryMovementEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity_delta' => 'integer', 'balance_after' => 'integer'];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }
}
