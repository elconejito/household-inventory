<?php

namespace App\Models;

use App\Enums\InventoryAlertType;
use App\Models\Concerns\HasNotes;
use Database\Factories\InventoryAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['household_id', 'item_id', 'alert_type', 'created_by', 'created_at', 'resolved_at', 'resolved_by'])]
class InventoryAlert extends Model
{
    /** @use HasFactory<InventoryAlertFactory> */
    use HasFactory, HasNotes;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'alert_type' => InventoryAlertType::class,
            'created_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed()->withInventorySummary();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
