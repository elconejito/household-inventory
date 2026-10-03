<?php

namespace App\Models\Concerns;

use App\Models\InventoryAlert;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Note;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasNotes
{
    protected static function bootHasNotes(): void
    {
        static::deleting(function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $model->notes()->withTrashed()->forceDelete();

            if ($model instanceof Item) {
                $movementIds = $model->inventoryMovements()->select('id');
                Note::withTrashed()
                    ->where('notable_type', (new InventoryMovement)->getMorphClass())
                    ->whereIn('notable_id', $movementIds)
                    ->forceDelete();

                $alertIds = $model->inventoryAlerts()->select('id');
                Note::withTrashed()
                    ->where('notable_type', (new InventoryAlert)->getMorphClass())
                    ->whereIn('notable_id', $alertIds)
                    ->forceDelete();
            }
        });
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable')->latest('created_at')->latest('id');
    }
}
