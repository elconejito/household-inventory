<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreItemImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'max:'.config('inventory.images_max_upload_kilobytes')],
            'data' => ['sometimes', 'array'],
            'data.caption' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $body = array_merge($this->getInputSource()->all(), $this->allFiles());
            foreach (array_diff(array_keys($body), ['image', 'data']) as $unexpectedKey) {
                $validator->errors()->add($unexpectedKey, 'This field is not allowed.');
            }

            $data = $this->input('data', []);
            if (is_array($data)) {
                foreach (array_diff(array_keys($data), ['caption']) as $unexpectedKey) {
                    $validator->errors()->add('data.'.$unexpectedKey, 'This field is not allowed.');
                }
            }

            if ($validator->errors()->has('image') || ! $this->hasFile('image')) {
                return;
            }

            $mimeType = $this->file('image')->getMimeType();
            $extension = strtolower($this->file('image')->getClientOriginalExtension());
            if (in_array($mimeType, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)
                || in_array($extension, ['heic', 'heif'], true)) {
                $validator->errors()->add('image', 'HEIC and HEIF photos are not supported by this server. Export the photo as JPEG, PNG, or WebP and upload it again.');
            } elseif (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $validator->errors()->add('image', 'The image must be a JPEG, PNG, or WebP photo.');
            }
        }];
    }
}
