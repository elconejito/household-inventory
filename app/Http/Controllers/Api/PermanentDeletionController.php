<?php

namespace App\Http\Controllers\Api;

use App\Actions\PermanentlyDeleteInventoryRecord;
use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\ItemImage;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PermanentDeletionController extends Controller
{
    public function item(Request $request, string $item, PermanentlyDeleteInventoryRecord $deletion): Response
    {
        $household = $this->household($request->user());
        $record = $household->items()->withTrashed()->findOrFail($item);
        Gate::authorize('forceDelete', $record);
        $deletion->permanentlyDeleteItem($household, $request->user(), $record);

        return response()->noContent();
    }

    public function category(Request $request, string $category, PermanentlyDeleteInventoryRecord $deletion): Response
    {
        $household = $this->household($request->user());
        $record = $household->categories()->withTrashed()->findOrFail($category);
        Gate::authorize('forceDelete', $record);
        $deletion->permanentlyDeleteCategory($household, $request->user(), $record);

        return response()->noContent();
    }

    public function location(Request $request, string $location, PermanentlyDeleteInventoryRecord $deletion): Response
    {
        $household = $this->household($request->user());
        $record = $household->locations()->withTrashed()->findOrFail($location);
        Gate::authorize('forceDelete', $record);
        $deletion->permanentlyDeleteLocation($household, $request->user(), $record);

        return response()->noContent();
    }

    public function note(Request $request, string $note, PermanentlyDeleteInventoryRecord $deletion): Response
    {
        $household = $this->household($request->user());
        $record = Note::query()->withTrashed()->findOrFail($note);
        $this->loadNotable($record);
        abort_unless($request->user()->households()->whereKey($record->notable->household_id)->exists(), 404);
        Gate::authorize('forceDelete', $record);
        $deletion->permanentlyDeleteNote($household, $request->user(), $record);

        return response()->noContent();
    }

    public function image(Request $request, string $item_image, PermanentlyDeleteInventoryRecord $deletion): Response
    {
        $household = $this->household($request->user());
        $record = ItemImage::query()->withTrashed()->whereHas('item', function ($items) use ($household): void {
            $items->where('household_id', $household->getKey());
        })->findOrFail($item_image);
        Gate::authorize('forceDelete', $record);
        $deletion->permanentlyDeleteImage($household, $request->user(), $record);

        return response()->noContent();
    }

    private function household(User $user): Household
    {
        return $user->households()->firstOrFail();
    }

    private function loadNotable(Note $note): void
    {
        $class = Relation::getMorphedModel($note->notable_type);
        abort_unless($class !== null && is_subclass_of($class, Model::class), 404);
        $query = $class::query();

        if (method_exists($class, 'trashed')) {
            $query->withTrashed();
        }

        $note->setRelation('notable', $query->findOrFail($note->notable_id));
    }
}
