<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class UpdateLocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $this->user()?->households()->firstOrFail()
            ->locations()
            ->findOrFail($this->route('location'));

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
            'data' => ['required', 'array:name,description,parent_id,id,type'],
            'data.id' => ['prohibited'],
            'data.type' => ['prohibited'],
            'data.name' => [
                'sometimes', 'required', 'string', 'max:255',
                $this->uniqueNameRule($this->desiredParentId()),
            ],
            'data.description' => ['sometimes', 'nullable', 'string'],
            'data.parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('locations', 'id')->where('household_id', $this->householdId())->whereNull('deleted_at'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = $this->input('data');

        if (is_array($data) && is_string($data['name'] ?? null)) {
            $data['name'] = Str::squish($data['name']);
            $this->merge(['data' => $data]);
        }
    }

    private function desiredParentId(): ?int
    {
        $data = $this->input('data', []);

        if (is_array($data) && array_key_exists('parent_id', $data)) {
            return is_numeric($data['parent_id']) ? (int) $data['parent_id'] : null;
        }

        $householdId = $this->householdId();

        if ($householdId === null) {
            return null;
        }

        return Location::query()
            ->where('household_id', $householdId)
            ->whereKey($this->route('location'))
            ->value('parent_id');
    }

    private function uniqueNameRule(?int $parentId): Unique
    {
        return Rule::unique('locations', 'name')
            ->where('household_id', $this->householdId())
            ->where('parent_id', $parentId)
            ->ignore($this->route('location'));
    }

    private function householdId(): ?int
    {
        return $this->user()?->households()->value('households.id');
    }
}
