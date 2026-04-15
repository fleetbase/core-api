<?php

namespace Fleetbase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CompanySettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $settings = $this->input('settings');
            if (!is_array($settings)) {
                return;  // base rule will flag it
            }

            // Reject indexed (list) arrays — must be associative.
            if (array_is_list($settings) && !empty($settings)) {
                $v->errors()->add('settings', 'settings must be an associative map of keys to values');
                return;
            }

            foreach ($settings as $key => $value) {
                if (!is_string($key) || $key === '') {
                    $v->errors()->add('settings', 'all settings keys must be non-empty strings');
                    continue;
                }

                if (!$this->isJsonSerializable($value)) {
                    $v->errors()->add("settings.{$key}", 'value must be scalar, null, or a JSON-serializable array');
                }
            }
        });
    }

    private function isJsonSerializable($value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if (!$this->isJsonSerializable($v)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}
