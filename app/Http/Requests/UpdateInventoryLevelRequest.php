<?php

namespace App\Http\Requests;

use App\Models\InventoryLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryLevelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $householdId = $user->households()->value('households.id');

        InventoryLevel::query()
            ->whereKey($this->route('inventory_level'))
            ->whereHas('item', fn ($query) => $query->where('household_id', $householdId))
            ->whereHas('location', fn ($query) => $query->where('household_id', $householdId))
            ->firstOrFail();

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data' => ['required', 'array:alert_threshold'],
            'data.alert_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
        ];
    }
}
