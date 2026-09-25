<?php

namespace Controlink\Templates\Http\Requests;

use Closure;
use Controlink\Templates\Types\TypeCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', config('templates.models.template'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->normalizeText($this->input('name')),
        ]);
    }

    public function rules(): array
    {
        $model = config('templates.models.template');
        $table = (new $model)->getConnectionName().'.'.(new $model)->getTable();

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique($table, 'name')],

            // The catalogue is configuration, not a table, so the code is validated
            // against the whole catalogue first — an unknown code gets the ordinary
            // "invalid value" message, localised through `attributes()`. Whether the
            // type is seeded is a separate question, answered by the closure below,
            // because a seeded type is obtained by duplicating an existing template,
            // never created from scratch. Both rules live here and not only on the
            // screen, because a hand-written POST is exactly how a select is bypassed.
            'type_code' => [
                'required',
                'string',
                'max:50',
                Rule::in(array_keys(app(TypeCatalogue::class)->all())),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && app(TypeCatalogue::class)->has($value) && app(TypeCatalogue::class)->find($value)->seeded) {
                        $fail(__('templates::editor.errors.type_not_creatable'));
                    }
                },
            ],
        ];
    }

    public function attributes(): array
    {
        return __('templates::editor.attributes');
    }

    protected function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
