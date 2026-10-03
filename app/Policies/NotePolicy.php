<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class NotePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Model $notable): bool
    {
        return $this->belongsToHouseholdModel($user, $notable);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Note $note): bool
    {
        return $this->belongsToHousehold($user, $note);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Model $notable): bool
    {
        return $this->belongsToHouseholdModel($user, $notable) && $this->isActive($notable);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Note $note): bool
    {
        return $this->belongsToHousehold($user, $note) && $this->parentIsActive($note);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Note $note): bool
    {
        return $this->update($user, $note);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Note $note): bool
    {
        return $this->belongsToHousehold($user, $note) && $this->parentIsActive($note);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Note $note): bool
    {
        $notable = $this->findNotableWithTrashed($note);

        return $notable !== null
            && $user->memberships()
                ->where('household_id', $notable->getAttribute('household_id'))
                ->where('role', MembershipRole::Owner->value)
                ->exists();
    }

    private function belongsToHousehold(User $user, Note $note): bool
    {
        $notable = $note->notable;

        return $notable !== null && $this->belongsToHouseholdModel($user, $notable);
    }

    private function parentIsActive(Note $note): bool
    {
        $notable = $note->notable;

        return $notable !== null && $this->isActive($notable);
    }

    private function belongsToHouseholdModel(User $user, Model $notable): bool
    {
        return $user->households()->whereKey($notable->getAttribute('household_id'))->exists();
    }

    private function isActive(Model $notable): bool
    {
        return ! method_exists($notable, 'trashed') || ! $notable->trashed();
    }

    private function findNotableWithTrashed(Note $note): ?Model
    {
        $modelClass = Relation::getMorphedModel($note->notable_type);
        if ($modelClass === null || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        $query = $modelClass::query();
        if (method_exists($modelClass, 'trashed')) {
            $query->withTrashed();
        }

        return $query->find($note->notable_id);
    }
}
